<?php

declare(strict_types=1);

namespace viesrood\imagekit\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * The credential fields reference env vars by default; they are resolved with
 * craft\helpers\App::parseEnv() via the getParsed*() getters. They can also be
 * overridden per environment via config/imagekit.php (Craft automatically
 * applies that file to these settings).
 */
class Settings extends Model
{
    /** Public API key (safe client-side, but we only use it server-side). */
    public string $publicKey = '$IMAGEKIT_PUBLIC_KEY';

    /** Private API key - server-side only, never expose it. */
    public string $privateKey = '$IMAGEKIT_PRIVATE_KEY';

    /** URL endpoint, e.g. https://ik.imagekit.io/<imagekit_id>. */
    public string $urlEndpoint = '$IMAGEKIT_URL_ENDPOINT';

    /** Default format when no `format` is given: auto/webp/avif/jpg/png. */
    public string $defaultFormat = 'auto';

    /** Default quality (1-100) when no `quality` is given; null = do not set. */
    public ?int $defaultQuality = 80;

    /** Sign URLs by default (HMAC-SHA1). Recommended for web proxy/private files. */
    public bool $signUrls = false;

    /** Validity period for signed URLs in seconds; 0 = non-expiring. */
    public int $signedExpire = 0;

    /** Target folder in the ImageKit Media Library for uploads. */
    public string $uploadFolder = '/uploads';

    /**
     * Serve native (untransformed) asset URLs in devMode. Useful when the local
     * origin (e.g. *.ddev.site) is not reachable for ImageKit's web proxy.
     * Only applies to Asset sources; string paths/URLs always produce ImageKit URLs.
     */
    public bool $useNativeInDevMode = false;

    /**
     * Allowed file extensions for uploads through the control panel utility.
     *
     * @var string[]
     */
    public array $uploadAllowedExtensions = ['avif', 'gif', 'heic', 'jpeg', 'jpg', 'png', 'svg', 'webp'];

    /** Maximum upload size in bytes for the utility; 0 = Craft's maxUploadFileSize. */
    public int $uploadMaxFileSize = 0;

    /**
     * Default widths for srcset generation when no explicit widths are given.
     *
     * @var int[]
     */
    private array $_defaultSrcsetWidths = [400, 800, 1200, 1600];

    /**
     * @return int[]
     */
    public function getDefaultSrcsetWidths(): array
    {
        return $this->_defaultSrcsetWidths;
    }

    /**
     * Accepts an array of widths (config file) or a comma-separated string
     * (control panel field). Non-positive and non-numeric entries are dropped.
     *
     * @param int[]|string $widths
     */
    public function setDefaultSrcsetWidths(array|string $widths): void
    {
        if (is_string($widths)) {
            $widths = preg_split('/[\s,]+/', $widths, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $normalized = [];
        foreach ($widths as $width) {
            $width = (int)$width;
            if ($width > 0) {
                $normalized[] = $width;
            }
        }

        if ($normalized !== []) {
            $this->_defaultSrcsetWidths = array_values(array_unique($normalized));
        }
    }

    /** The resolved public key ('' when unset). */
    public function getParsedPublicKey(): string
    {
        return (string)(App::parseEnv($this->publicKey) ?? '');
    }

    /** The resolved private key ('' when unset). */
    public function getParsedPrivateKey(): string
    {
        return (string)(App::parseEnv($this->privateKey) ?? '');
    }

    /** The resolved URL endpoint ('' when unset). */
    public function getParsedUrlEndpoint(): string
    {
        return (string)(App::parseEnv($this->urlEndpoint) ?? '');
    }

    public function rules(): array
    {
        return [
            [['publicKey', 'privateKey', 'urlEndpoint'], 'string'],
            [['defaultFormat', 'uploadFolder'], 'string'],
            [['defaultFormat'], 'in', 'range' => ['auto', 'webp', 'avif', 'jpg', 'jpeg', 'png', '']],
            [['defaultQuality'], 'integer', 'min' => 1, 'max' => 100, 'skipOnEmpty' => true],
            [['signedExpire', 'uploadMaxFileSize'], 'integer', 'min' => 0],
            [['signUrls', 'useNativeInDevMode'], 'boolean'],
        ];
    }
}
