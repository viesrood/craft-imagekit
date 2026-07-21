<?php

declare(strict_types=1);

namespace viesrood\imagekit\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use ImageKit\ImageKit as ImageKitClient;
use viesrood\imagekit\models\Settings;
use viesrood\imagekit\Plugin;
use yii\base\InvalidConfigException;

/**
 * Dunne wrapper om de officiele imagekit/imagekit-SDK.
 *
 * - url(): bouwt een (optioneel ondertekende) transformatie-URL voor een Media
 *   Library-pad of een bestaande externe URL (web-proxy).
 * - upload(): uploadt een lokaal bestand of publieke URL naar de Media Library.
 */
class Imagekit extends Component
{
    private ?ImageKitClient $client = null;

    private ?ImageKitClient $urlClient = null;

    /**
     * Map van "vriendelijke" optienamen naar SDK-transformatiekeys.
     */
    private const OPTION_MAP = [
        'width' => 'width',
        'w' => 'width',
        'height' => 'height',
        'h' => 'height',
        'format' => 'format',
        'f' => 'format',
        'quality' => 'quality',
        'q' => 'quality',
        'crop' => 'crop',
        'c' => 'crop',
        'cropMode' => 'cropMode',
        'cm' => 'cropMode',
        'x' => 'x',
        'y' => 'y',
        'focus' => 'focus',
        'fo' => 'focus',
        'aspectRatio' => 'aspectRatio',
        'ar' => 'aspectRatio',
        'dpr' => 'dpr',
        'blur' => 'blur',
        'bl' => 'blur',
        'radius' => 'radius',
        'r' => 'radius',
        'rotation' => 'rotation',
        'rt' => 'rotation',
        'background' => 'background',
        'bg' => 'background',
    ];

    /**
     * Bouw een ImageKit-transformatie-URL.
     *
     * Accepteert zowel een Craft {@see Asset} als een string-bron:
     * - Asset: de publieke asset-URL wordt tot een pad t.o.v. het endpoint herleid en
     *   crops honoreren het per-asset focuspunt ({@see focalCropTransformation()}).
     *   Ondersteunt de "vriendelijke" opties `mode` (`crop`/`fit`), `width`, `height`,
     *   plus alles uit {@see OPTION_MAP} (quality/format/…). Null asset -> ''. Zonder
     *   geconfigureerd endpoint valt hij terug op een native Craft-transform-URL.
     * - string: Media Library-pad (bv. /map/foto.jpg) of een absolute (externe) URL.
     *
     * @param Asset|string|null $source
     * @param array<string,mixed> $options Transformatie- en URL-opties (zie OPTION_MAP + mode/signed/expire).
     */
    public function url(Asset|string|null $source, array $options = []): string
    {
        if ($source instanceof Asset) {
            return $this->urlForAsset($source, $options);
        }

        if ($source === null || $source === '') {
            return '';
        }

        return $this->urlForString($source, $options);
    }

    /**
     * Bouw een transformatie-URL voor een Craft-asset (focuspunt-bewust).
     *
     * @param array<string,mixed> $options
     */
    private function urlForAsset(Asset $asset, array $options): string
    {
        $settings = $this->settings();

        // Escape-hatch: geen endpoint geconfigureerd -> native Craft-transform (net als de
        // oude custom module). Opties zijn 1-op-1 een Craft-transformdefinitie (mode/width/
        // height/quality/format). parseEnv() geeft null terug voor een niet-bestaande env-var,
        // dus niet alleen op '' controleren.
        if ((string)(App::parseEnv($settings->urlEndpoint) ?? '') === '') {
            return $asset->getUrl($this->assetTransformOptions($options)) ?? '';
        }

        // signed/expire uit de opties lichten; de rest zijn transformaties.
        $signed = array_key_exists('signed', $options)
            ? (bool)$options['signed']
            : $settings->signUrls;
        $expire = (int)($options['expire'] ?? $options['expireSeconds'] ?? $settings->signedExpire);
        unset($options['signed'], $options['expire'], $options['expireSeconds']);

        // Structurele opties apart houden; de rest (quality/format/…) rijdt mee op de
        // laatste transform-stap.
        $mode = (string)($options['mode'] ?? 'crop');
        $targetW = (int)($options['width'] ?? 0);
        $targetH = (int)($options['height'] ?? 0);
        unset($options['mode'], $options['width'], $options['height']);

        $output = $this->buildTransformation($options, $settings);
        $transformation = $this->assetTransformation($asset, $mode, $targetW, $targetH, $output);

        $params = [
            'path' => $this->assetPath($asset, $settings),
            'transformation' => $transformation,
        ];

        if ($signed) {
            $params['signed'] = true;
            if ($expire > 0) {
                $params['expireSeconds'] = $expire;
            }
        }

        return $this->urlClient()->url($params);
    }

    /**
     * Vertaal `mode`/`width`/`height` naar een (mogelijk geketende) ImageKit-transformatie.
     *
     * @param array<string,string> $output Reeds gemapte output-opties (quality/format/…).
     * @return array<int,array<string,string>>
     */
    private function assetTransformation(Asset $asset, string $mode, int $targetW, int $targetH, array $output): array
    {
        if ($mode === 'fit') {
            $t = $output;
            if ($targetW) {
                $t['width'] = (string)$targetW;
            }
            if ($targetH) {
                $t['height'] = (string)$targetH;
            }
            $t['crop'] = 'at_max';
            return [$t];
        }

        if ($mode === 'crop' && $targetW && $targetH) {
            return $this->focalCropTransformation($asset, $targetW, $targetH, $output);
        }

        // Overige modes / ontbrekende dimensie: gewone resize.
        $t = $output;
        if ($targetW) {
            $t['width'] = (string)$targetW;
        }
        if ($targetH) {
            $t['height'] = (string)$targetH;
        }
        return [$t];
    }

    /**
     * Focuspunt-bewuste crop naar exact $targetW x $targetH.
     *
     * Zonder (relevant) focuspunt: gewone crop-tot-vullend (ImageKit default maintain_ratio,
     * gecentreerd). Met focuspunt: twee-staps transform - eerst opschalen tot dekking
     * (`c-force`), dan `cm-extract` rondom het focuspunt. Poort van de oude custom module.
     *
     * @param array<string,string> $output
     * @return array<int,array<string,string>>
     */
    private function focalCropTransformation(Asset $asset, int $targetW, int $targetH, array $output): array
    {
        $origW = (int)$asset->width;
        $origH = (int)$asset->height;
        $fp = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null;

        $hasFocalPoint = is_array($fp) && $origW && $origH
            && (abs($fp['x'] - 0.5) > 0.02 || abs($fp['y'] - 0.5) > 0.02);

        if (!$hasFocalPoint) {
            $t = $output;
            $t['width'] = (string)$targetW;
            $t['height'] = (string)$targetH;
            return [$t];
        }

        $scale = max($targetW / $origW, $targetH / $origH);
        $scaledW = (int)round($origW * $scale);
        $scaledH = (int)round($origH * $scale);

        $fpX = $fp['x'] * $scaledW;
        $fpY = $fp['y'] * $scaledH;

        $cropX = (int)max(0, min($scaledW - $targetW, round($fpX - $targetW / 2)));
        $cropY = (int)max(0, min($scaledH - $targetH, round($fpY - $targetH / 2)));

        $step1 = [
            'width' => (string)$scaledW,
            'height' => (string)$scaledH,
            'crop' => 'force',
        ];
        $step2 = array_merge([
            'cropMode' => 'extract',
            'x' => (string)$cropX,
            'y' => (string)$cropY,
            'width' => (string)$targetW,
            'height' => (string)$targetH,
        ], $output);

        return [$step1, $step2];
    }

    /**
     * Herleid het endpoint-relatieve pad van een asset.
     */
    private function assetPath(Asset $asset, Settings $settings): string
    {
        $assetUrl = (string)$asset->getUrl();

        // Al onder ons eigen ImageKit-endpoint: neem de rest als Media Library-pad.
        $endpoint = rtrim((string)(App::parseEnv($settings->urlEndpoint) ?? ''), '/');
        if ($endpoint !== '' && str_starts_with($assetUrl, $endpoint . '/')) {
            return '/' . ltrim(substr($assetUrl, strlen($endpoint) + 1), '/');
        }

        // Anders: strip het site-origin -> pad t.o.v. de origin (ImageKit-endpoint met origin).
        $siteUrl = rtrim((string)App::env('PRIMARY_SITE_URL'), '/');
        if ($siteUrl !== '' && str_starts_with($assetUrl, $siteUrl)) {
            return '/' . ltrim(substr($assetUrl, strlen($siteUrl)), '/');
        }

        // Fallback: pad uit de URL parsen.
        $path = parse_url($assetUrl, PHP_URL_PATH) ?: $assetUrl;
        return '/' . ltrim($path, '/');
    }

    /**
     * Maak van de vriendelijke opties een Craft-transformdefinitie (voor de native fallback).
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function assetTransformOptions(array $options): array
    {
        unset($options['signed'], $options['expire'], $options['expireSeconds']);
        return $options;
    }

    /**
     * Bouw een transformatie-URL voor een string-bron (pad of externe URL).
     *
     * @param array<string,mixed> $options Transformatie- en URL-opties (zie OPTION_MAP + signed/expire).
     */
    private function urlForString(string $source, array $options = []): string
    {
        $settings = $this->settings();
        $client = $this->urlClient();

        // signed/expire uit de opties lichten; de rest zijn transformaties.
        $signed = array_key_exists('signed', $options)
            ? (bool)$options['signed']
            : $settings->signUrls;
        $expire = (int)($options['expire'] ?? $options['expireSeconds'] ?? $settings->signedExpire);
        unset($options['signed'], $options['expire'], $options['expireSeconds']);

        $transformation = $this->buildTransformation($options, $settings);

        $params = [
            'transformation' => [$transformation],
        ];

        if ($signed) {
            $params['signed'] = true;
            if ($expire > 0) {
                $params['expireSeconds'] = $expire;
            }
        }

        if ($this->isAbsoluteUrl($source)) {
            $endpoint = rtrim((string)(App::parseEnv($settings->urlEndpoint) ?? ''), '/');
            $prefix = $endpoint . '/';

            if (str_starts_with($source, $prefix)) {
                // Al onder ons eigen endpoint: behandel de rest als Media Library-pad.
                $params['path'] = '/' . ltrim(substr($source, strlen($prefix)), '/');
            } else {
                // Externe URL: canonieke web-proxy-vorm endpoint/tr:.../<volledige externe URL>.
                // Via `path` (niet `src`), zodat de transformatie in het pad komt en signed
                // URL's blijven werken (de SDK kan alleen ondertekenen met endpoint + path).
                $params['path'] = $source;
            }
        } else {
            $params['path'] = '/' . ltrim($source, '/');
        }

        return $client->url($params);
    }

    /**
     * Bouw een srcset-string voor responsive afbeeldingen.
     *
     * @param Asset|string $source
     * @param int[] $widths
     * @param array<string,mixed> $options
     */
    public function srcset(Asset|string $source, array $widths, array $options = []): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $width = (int)$width;
            if ($width <= 0) {
                continue;
            }
            $url = $this->url($source, array_merge($options, ['width' => $width]));
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * Upload een bestand of publieke URL naar de ImageKit Media Library.
     *
     * @param string $file Lokaal bestandspad, base64-string of publieke URL.
     * @param array<string,mixed> $options fileName, folder, useUniqueFileName, tags, ...
     * @return array{url:?string,filePath:?string,fileId:?string,name:?string,width:?int,height:?int,size:?int}
     */
    public function upload(string $file, array $options = []): array
    {
        $settings = $this->settings();
        $client = $this->client();

        $payload = [
            'file' => $this->prepareFileArgument($file),
            'fileName' => $options['fileName'] ?? basename($file) ?: 'upload',
            'folder' => $options['folder'] ?? $settings->uploadFolder,
            'useUniqueFileName' => $options['useUniqueFileName'] ?? true,
        ];

        if (!empty($options['tags'])) {
            $payload['tags'] = is_array($options['tags'])
                ? implode(',', $options['tags'])
                : (string)$options['tags'];
        }

        $response = $client->uploadFile($payload);

        $error = $response->error ?? ($response->err ?? null);
        if (!empty($error)) {
            $message = is_string($error) ? $error : json_encode($error);
            throw new \RuntimeException('ImageKit-upload mislukt: ' . $message);
        }

        $result = $response->result ?? null;
        if ($result === null) {
            throw new \RuntimeException('ImageKit-upload gaf geen resultaat terug.');
        }

        return [
            'url' => $result->url ?? null,
            'filePath' => $result->filePath ?? null,
            'fileId' => $result->fileId ?? null,
            'name' => $result->name ?? null,
            'width' => isset($result->width) ? (int)$result->width : null,
            'height' => isset($result->height) ? (int)$result->height : null,
            'size' => isset($result->size) ? (int)$result->size : null,
        ];
    }

    /**
     * Is de plugin bruikbaar (zijn de verplichte credentials gezet)?
     */
    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return App::parseEnv($settings->privateKey) !== ''
            && App::parseEnv($settings->publicKey) !== ''
            && App::parseEnv($settings->urlEndpoint) !== '';
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    private function buildTransformation(array $options, Settings $settings): array
    {
        $transformation = [];

        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $sdkKey = self::OPTION_MAP[$key] ?? $key;
            $transformation[$sdkKey] = (string)$value;
        }

        // Defaults toepassen wanneer niet expliciet opgegeven.
        if (!isset($transformation['format']) && $settings->defaultFormat !== '') {
            $transformation['format'] = $settings->defaultFormat;
        }
        if (!isset($transformation['quality']) && $settings->defaultQuality !== null) {
            $transformation['quality'] = (string)$settings->defaultQuality;
        }

        return $transformation;
    }

    private function prepareFileArgument(string $file): string
    {
        // Lokaal, bestaand bestand -> base64 meesturen (SDK accepteert base64 of URL).
        if (is_file($file)) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException("Kon bestand niet lezen: {$file}");
            }
            return base64_encode($contents);
        }

        // Anders: publieke URL of al-base64 -> zoals aangeleverd.
        return $file;
    }

    private function isAbsoluteUrl(string $source): bool
    {
        return str_starts_with($source, 'http://') || str_starts_with($source, 'https://');
    }

    private function settings(): Settings
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            throw new InvalidConfigException('ImageKit-plugin is niet geinitialiseerd.');
        }

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        return $settings;
    }

    private function client(): ImageKitClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $settings = $this->settings();
        $publicKey = App::parseEnv($settings->publicKey);
        $privateKey = App::parseEnv($settings->privateKey);
        $urlEndpoint = App::parseEnv($settings->urlEndpoint);

        if ($privateKey === '' || $publicKey === '' || $urlEndpoint === '') {
            throw new InvalidConfigException(
                'ImageKit-credentials ontbreken. Zet IMAGEKIT_PUBLIC_KEY, IMAGEKIT_PRIVATE_KEY ' .
                'en IMAGEKIT_URL_ENDPOINT in .env (of config/imagekit.php).'
            );
        }

        return $this->client = new ImageKitClient($publicKey, $privateKey, $urlEndpoint);
    }

    /**
     * Client voor het bouwen van URL's. Werkt ook (deels) zonder complete configuratie:
     * ontbrekende keys/endpoint worden vervangen door duidelijke placeholders, zodat een
     * template niet crasht wanneer de credentials nog niet zijn gezet. Ondertekende URL's
     * hebben uiteraard wel een echte private key nodig om geldig te zijn.
     */
    private function urlClient(): ImageKitClient
    {
        if ($this->urlClient !== null) {
            return $this->urlClient;
        }

        $settings = $this->settings();
        $publicKey = App::parseEnv($settings->publicKey) ?: 'public_unconfigured';
        $privateKey = App::parseEnv($settings->privateKey) ?: 'private_unconfigured';
        $urlEndpoint = App::parseEnv($settings->urlEndpoint) ?: 'https://ik.imagekit.io/your_imagekit_id';

        return $this->urlClient = new ImageKitClient($publicKey, $privateKey, $urlEndpoint);
    }
}
