<?php

declare(strict_types=1);

namespace viesrood\imagekit\services;

use Craft;
use craft\base\Component;
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
     * @param string $source Media Library-pad (bv. /map/foto.jpg) of een absolute (externe) URL.
     * @param array<string,mixed> $options Transformatie- en URL-opties (zie OPTION_MAP + signed/expire).
     */
    public function url(string $source, array $options = []): string
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
            $endpoint = rtrim(App::parseEnv($settings->urlEndpoint), '/');
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
     * @param int[] $widths
     * @param array<string,mixed> $options
     */
    public function srcset(string $source, array $widths, array $options = []): string
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
