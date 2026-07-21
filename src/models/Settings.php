<?php

declare(strict_types=1);

namespace viesrood\imagekit\models;

use craft\base\Model;

/**
 * Plugin settings.
 *
 * The credential fields reference env vars by default; they are resolved in the
 * service with craft\helpers\App::parseEnv(). They can also be overridden per
 * environment via config/imagekit.php (Craft automatically applies that file
 * to these settings).
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

    public function rules(): array
    {
        return [
            [['publicKey', 'privateKey', 'urlEndpoint'], 'string'],
            [['defaultFormat', 'uploadFolder'], 'string'],
            [['defaultQuality', 'signedExpire'], 'integer'],
            [['signUrls'], 'boolean'],
        ];
    }
}
