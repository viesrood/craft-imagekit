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

    /** Guard so the "not configured" warning is logged once per request. */
    private bool $warnedUnconfigured = false;

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
        if ($settings->getParsedUrlEndpoint() === '') {
            $this->warnUnconfigured();
            return $asset->getUrl($this->assetTransformOptions($options)) ?? '';
        }

        $sign = $this->extractSignParams($options, $settings);
        $chain = $this->extractChain($options);
        $overlays = $this->extractOverlays($options);

        // Keep the structural options separate; the rest (quality/format/...)
        // rides along on the last transform step.
        $mode = (string)($options['mode'] ?? 'crop');
        $targetW = (int)($options['width'] ?? 0);
        $targetH = (int)($options['height'] ?? 0);
        unset($options['mode'], $options['width'], $options['height']);

        // With a chain, the format/quality defaults move to the last chain step.
        $output = $chain === []
            ? Transformation::mapOptions($options, $settings->defaultFormat, $settings->defaultQuality)
            : Transformation::mapOptions($options, null, null);
        $transformation = $this->assetTransformation($asset, $mode, $targetW, $targetH, $output);
        $transformation = $this->appendExtraSteps($transformation, $chain, $overlays, $settings);

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
        $endpoint = rtrim($settings->getParsedUrlEndpoint(), '/');
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
        unset(
            $options['signed'],
            $options['expire'],
            $options['expireSeconds'],
            $options['chain'],
            $options['overlay'],
        );
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

        // Not configured: never emit placeholder-endpoint URLs. An absolute URL
        // is returned untouched so the page keeps working; a Media Library path
        // has no meaningful fallback.
        if ($settings->getParsedUrlEndpoint() === '') {
            $this->warnUnconfigured();
            return $this->isAbsoluteUrl($source) ? $source : '';
        }

        $sign = $this->extractSignParams($options, $settings);
        $chain = $this->extractChain($options);
        $overlays = $this->extractOverlays($options);

        // With a chain, the format/quality defaults move to the last chain step.
        $transformation = [
            $chain === []
                ? Transformation::mapOptions($options, $settings->defaultFormat, $settings->defaultQuality)
                : Transformation::mapOptions($options, null, null),
        ];
        $transformation = $this->appendExtraSteps($transformation, $chain, $overlays, $settings);

        $params = [
            'transformation' => $transformation,
        ];

        if ($this->isAbsoluteUrl($source)) {
            $endpoint = rtrim($settings->getParsedUrlEndpoint(), '/');
            $prefix = $endpoint . '/';

            if (str_starts_with($source, $prefix)) {
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

        return $settings->getParsedPrivateKey() !== ''
            && $settings->getParsedPublicKey() !== ''
            && $settings->getParsedUrlEndpoint() !== '';
    }

    // ---------------------------------------------------------------------

    /**
     * Extract the `chain` option: an array of friendly option maps that become
     * additional chained transformation steps.
     *
     * @param array<string,mixed> $options Modified in place: the chain key is removed.
     * @return array<int,array<string,mixed>>
     */
    private function extractChain(array &$options): array
    {
        $chain = $options['chain'] ?? [];
        unset($options['chain']);

        return is_array($chain) ? array_values($chain) : [];
    }

    /**
     * Extract the `overlay` option: a single overlay definition or a list of
     * them (see Transformation::buildLayer() for the accepted keys).
     *
     * @param array<string,mixed> $options Modified in place: the overlay key is removed.
     * @return array<int,array<string,mixed>>
     */
    private function extractOverlays(array &$options): array
    {
        $overlay = $options['overlay'] ?? null;
        unset($options['overlay']);

        if (!is_array($overlay) || $overlay === []) {
            return [];
        }

        return array_is_list($overlay) ? $overlay : [$overlay];
    }

    /**
     * Append chain and overlay steps to a main transformation, applying the
     * format/quality defaults to the last chain step (the main steps were
     * mapped without defaults when a chain is present) and dropping empty steps.
     *
     * @param array<int,array<string,string>> $transformation
     * @param array<int,array<string,mixed>> $chain
     * @param array<int,array<string,mixed>> $overlays
     * @return array<int,array<string,string>>
     */
    private function appendExtraSteps(array $transformation, array $chain, array $overlays, Settings $settings): array
    {
        if ($chain !== []) {
            $last = array_pop($chain);
            foreach ($chain as $step) {
                $transformation[] = Transformation::mapOptions((array)$step, null, null);
            }
            $transformation[] = Transformation::mapOptions((array)$last, $settings->defaultFormat, $settings->defaultQuality);
        }

        foreach ($overlays as $overlay) {
            $transformation[] = ['raw' => Transformation::buildLayer($overlay)];
        }

        return array_values(array_filter($transformation, static fn(array $step): bool => $step !== []));
    }

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
            if ($this->settings()->getParsedPrivateKey() === '') {
                // A signature computed with a placeholder key would silently be
                // invalid; an unsigned URL at least keeps working on endpoints
                // that do not restrict unsigned requests.
                Craft::error(
                    'A signed ImageKit URL was requested but no private key is configured; returning an unsigned URL.',
                    __METHOD__
                );
            } else {
                $params['signed'] = true;
                if ($sign['expire'] > 0) {
                    $params['expireSeconds'] = $sign['expire'];
                }
            }
        }

        return $this->urlClient()->url($params);
    }

    /**
     * Log a single warning per request when URLs are requested while the
     * plugin is not (fully) configured.
     */
    private function warnUnconfigured(): void
    {
        if ($this->warnedUnconfigured) {
            return;
        }
        $this->warnedUnconfigured = true;

        Craft::warning(
            'ImageKit URL requested but the plugin is not configured (missing URL endpoint); returning fallback URLs. ' .
            'Set IMAGEKIT_PUBLIC_KEY, IMAGEKIT_PRIVATE_KEY and IMAGEKIT_URL_ENDPOINT in .env (or config/imagekit.php).',
            __METHOD__
        );
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
        $publicKey = $settings->getParsedPublicKey();
        $privateKey = $settings->getParsedPrivateKey();
        $urlEndpoint = $settings->getParsedUrlEndpoint();

        if ($privateKey === '' || $publicKey === '' || $urlEndpoint === '') {
            throw new InvalidConfigException(
                'ImageKit credentials are missing. Set IMAGEKIT_PUBLIC_KEY, IMAGEKIT_PRIVATE_KEY ' .
                'and IMAGEKIT_URL_ENDPOINT in .env (or config/imagekit.php).'
            );
        }

        return $this->client = new ImageKitClient($publicKey, $privateKey, $urlEndpoint);
    }

    /**
     * Client for building URLs. Requires a configured URL endpoint (callers
     * fall back before reaching this point when it is missing). The API keys
     * never appear in unsigned URLs, so partially configured installs still
     * get correct URLs; signing without a real private key is refused in
     * buildUrl() with an error log.
     */
    private function urlClient(): ImageKitClient
    {
        if ($this->urlClient !== null) {
            return $this->urlClient;
        }

        $settings = $this->settings();
        $urlEndpoint = $settings->getParsedUrlEndpoint();

        if ($urlEndpoint === '') {
            throw new InvalidConfigException(
                'ImageKit URL endpoint is missing. Set IMAGEKIT_URL_ENDPOINT in .env (or config/imagekit.php).'
            );
        }

        // The SDK constructor requires non-empty keys; these placeholders are
        // never emitted in unsigned URLs.
        $publicKey = $settings->getParsedPublicKey() ?: 'public_unconfigured';
        $privateKey = $settings->getParsedPrivateKey() ?: 'private_unconfigured';

        return $this->urlClient = new ImageKitClient($publicKey, $privateKey, $urlEndpoint);
    }
}
