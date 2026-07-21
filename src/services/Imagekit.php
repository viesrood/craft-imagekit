<?php

declare(strict_types=1);

namespace viesrood\imagekit\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\App;
use ImageKit\ImageKit as ImageKitClient;
use viesrood\imagekit\helpers\Transformation;
use viesrood\imagekit\models\Settings;
use viesrood\imagekit\Plugin;
use yii\base\InvalidConfigException;

/**
 * Thin wrapper around the official imagekit/imagekit SDK.
 *
 * - url(): builds an (optionally signed) transformation URL for a Media
 *   Library path or an existing external URL (web proxy).
 * - upload(): uploads a local file or public URL to the Media Library.
 */
class Imagekit extends Component
{
    private ?ImageKitClient $client = null;

    private ?ImageKitClient $urlClient = null;

    /**
     * Build an ImageKit transformation URL.
     *
     * Accepts both a Craft {@see Asset} and a string source:
     * - Asset: the public asset URL is resolved to a path relative to the
     *   endpoint, and crops honor the per-asset focal point. Supports the
     *   friendly options `mode` (`crop`/`fit`), `width`, `height`, plus
     *   everything in {@see Transformation::OPTION_MAP} (quality/format/...).
     *   A null asset returns ''. Without a configured endpoint it falls back
     *   to a native Craft transform URL.
     * - string: Media Library path (e.g. /folder/photo.jpg) or an absolute
     *   (external) URL.
     *
     * @param Asset|string|null $source
     * @param array<string,mixed> $options Transformation and URL options (see OPTION_MAP + mode/signed/expire).
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
     * Build a transformation URL for a Craft asset (focal-point aware).
     *
     * @param array<string,mixed> $options
     */
    private function urlForAsset(Asset $asset, array $options): string
    {
        $settings = $this->settings();

        // Escape hatch: no endpoint configured -> native Craft transform. The
        // options map 1-to-1 onto a Craft transform definition (mode/width/
        // height/quality/format).
        if ($this->parsedUrlEndpoint($settings) === '') {
            return $asset->getUrl($this->assetTransformOptions($options)) ?? '';
        }

        $sign = $this->extractSignParams($options, $settings);

        // Keep the structural options separate; the rest (quality/format/...)
        // rides along on the last transform step.
        $mode = (string)($options['mode'] ?? 'crop');
        $targetW = (int)($options['width'] ?? 0);
        $targetH = (int)($options['height'] ?? 0);
        unset($options['mode'], $options['width'], $options['height']);

        $output = Transformation::mapOptions($options, $settings->defaultFormat, $settings->defaultQuality);
        $transformation = $this->assetTransformation($asset, $mode, $targetW, $targetH, $output);

        $params = [
            'path' => $this->assetPath($asset, $settings),
            'transformation' => $transformation,
        ];

        return $this->buildUrl($params, $sign);
    }

    /**
     * Translate `mode`/`width`/`height` into a (possibly chained) ImageKit transformation.
     *
     * @param array<string,string> $output Already-mapped output options (quality/format/...).
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
            $fp = $asset->getHasFocalPoint() ? $asset->getFocalPoint() : null;

            return Transformation::focalCropSteps(
                $targetW,
                $targetH,
                (int)$asset->width,
                (int)$asset->height,
                is_array($fp) ? (float)$fp['x'] : null,
                is_array($fp) ? (float)$fp['y'] : null,
                $output,
            );
        }

        // Other modes / missing dimension: plain resize.
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
     * Resolve the endpoint-relative path of an asset.
     */
    private function assetPath(Asset $asset, Settings $settings): string
    {
        $assetUrl = (string)$asset->getUrl();

        // Already under our own ImageKit endpoint: use the rest as the Media Library path.
        $endpoint = rtrim($this->parsedUrlEndpoint($settings), '/');
        if ($endpoint !== '' && str_starts_with($assetUrl, $endpoint . '/')) {
            return '/' . ltrim(substr($assetUrl, strlen($endpoint) + 1), '/');
        }

        // Otherwise: strip the site origin -> path relative to the origin
        // (an ImageKit endpoint with an attached origin).
        $siteUrl = rtrim((string)App::env('PRIMARY_SITE_URL'), '/');
        if ($siteUrl !== '' && str_starts_with($assetUrl, $siteUrl)) {
            return '/' . ltrim(substr($assetUrl, strlen($siteUrl)), '/');
        }

        // Fallback: parse the path out of the URL.
        $path = parse_url($assetUrl, PHP_URL_PATH) ?: $assetUrl;
        return '/' . ltrim($path, '/');
    }

    /**
     * Turn the friendly options into a Craft transform definition (for the native fallback).
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
     * Build a transformation URL for a string source (path or external URL).
     *
     * @param array<string,mixed> $options Transformation and URL options (see OPTION_MAP + signed/expire).
     */
    private function urlForString(string $source, array $options = []): string
    {
        $settings = $this->settings();

        $sign = $this->extractSignParams($options, $settings);

        $transformation = Transformation::mapOptions($options, $settings->defaultFormat, $settings->defaultQuality);

        $params = [
            'transformation' => [$transformation],
        ];

        if ($this->isAbsoluteUrl($source)) {
            $endpoint = rtrim($this->parsedUrlEndpoint($settings), '/');
            $prefix = $endpoint . '/';

            if ($endpoint !== '' && str_starts_with($source, $prefix)) {
                // Already under our own endpoint: treat the rest as a Media Library path.
                $params['path'] = '/' . ltrim(substr($source, strlen($prefix)), '/');
            } else {
                // External URL: canonical web-proxy form endpoint/tr:.../<full external URL>.
                // Via `path` (not `src`), so the transformation ends up in the path and
                // signed URLs keep working (the SDK can only sign endpoint + path).
                $params['path'] = $source;
            }
        } else {
            $params['path'] = '/' . ltrim($source, '/');
        }

        return $this->buildUrl($params, $sign);
    }

    /**
     * Build a srcset string for responsive images.
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
     * Upload a file or public URL to the ImageKit Media Library.
     *
     * @param string $file Local file path, base64 string, or public URL.
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
            throw new \RuntimeException('ImageKit upload failed: ' . $message);
        }

        $result = $response->result ?? null;
        if ($result === null) {
            throw new \RuntimeException('ImageKit upload did not return a result.');
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
     * Is the plugin usable (are the required credentials set)?
     */
    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return (App::parseEnv($settings->privateKey) ?? '') !== ''
            && (App::parseEnv($settings->publicKey) ?? '') !== ''
            && $this->parsedUrlEndpoint($settings) !== '';
    }

    // ---------------------------------------------------------------------

    /**
     * Extract the signing options (signed/expire) from the option array.
     *
     * @param array<string,mixed> $options Modified in place: signing keys are removed.
     * @return array{signed: bool, expire: int}
     */
    private function extractSignParams(array &$options, Settings $settings): array
    {
        $signed = array_key_exists('signed', $options)
            ? (bool)$options['signed']
            : $settings->signUrls;
        $expire = (int)($options['expire'] ?? $options['expireSeconds'] ?? $settings->signedExpire);
        unset($options['signed'], $options['expire'], $options['expireSeconds']);

        return ['signed' => $signed, 'expire' => $expire];
    }

    /**
     * Run the assembled SDK params through the URL client, applying signing.
     *
     * @param array<string,mixed> $params
     * @param array{signed: bool, expire: int} $sign
     */
    private function buildUrl(array $params, array $sign): string
    {
        if ($sign['signed']) {
            $params['signed'] = true;
            if ($sign['expire'] > 0) {
                $params['expireSeconds'] = $sign['expire'];
            }
        }

        return $this->urlClient()->url($params);
    }

    /**
     * The parsed URL endpoint ('' when unset). parseEnv() returns null for an
     * undefined env var, so this cannot simply check for ''.
     */
    private function parsedUrlEndpoint(Settings $settings): string
    {
        return (string)(App::parseEnv($settings->urlEndpoint) ?? '');
    }

    private function prepareFileArgument(string $file): string
    {
        // Local, existing file -> send as base64 (the SDK accepts base64 or a URL).
        if (is_file($file)) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException("Could not read file: {$file}");
            }
            return base64_encode($contents);
        }

        // Otherwise: public URL or already-base64 -> pass through as-is.
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
            throw new InvalidConfigException('The ImageKit plugin has not been initialized.');
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
                'ImageKit credentials are missing. Set IMAGEKIT_PUBLIC_KEY, IMAGEKIT_PRIVATE_KEY ' .
                'and IMAGEKIT_URL_ENDPOINT in .env (or config/imagekit.php).'
            );
        }

        return $this->client = new ImageKitClient($publicKey, $privateKey, $urlEndpoint);
    }

    /**
     * Client for building URLs. Also works (partially) without complete
     * configuration: missing keys/endpoint are replaced by clear placeholders
     * so a template does not crash while credentials are not set yet. Signed
     * URLs obviously need a real private key to be valid.
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
