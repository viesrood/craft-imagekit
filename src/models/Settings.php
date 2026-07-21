<?php

declare(strict_types=1);

namespace viesrood\imagekit\models;

use craft\base\Model;

/**
 * Plugin-instellingen.
 *
 * De credential-velden verwijzen standaard naar env-vars; ze worden in de service
 * opgelost met craft\helpers\App::parseEnv(). Overschrijven kan ook per omgeving via
 * config/imagekit.php (Craft past dat bestand automatisch toe op deze settings).
 */
class Settings extends Model
{
    /** Publieke API-key (mag client-side, maar wij gebruiken 'm server-side). */
    public string $publicKey = '$IMAGEKIT_PUBLIC_KEY';

    /** Private API-key - alleen server-side, nooit blootstellen. */
    public string $privateKey = '$IMAGEKIT_PRIVATE_KEY';

    /** URL-endpoint, bv. https://ik.imagekit.io/<imagekit_id>. */
    public string $urlEndpoint = '$IMAGEKIT_URL_ENDPOINT';

    /** Standaard-formaat wanneer geen `format` is opgegeven: auto/webp/avif/jpg/png. */
    public string $defaultFormat = 'auto';

    /** Standaard-kwaliteit (1-100) wanneer geen `quality` is opgegeven; null = niet zetten. */
    public ?int $defaultQuality = 80;

    /** URL's standaard ondertekenen (HMAC-SHA1). Aanraders bij web-proxy/privebestanden. */
    public bool $signUrls = false;

    /** Geldigheidsduur voor signed URL's in seconden; 0 = niet-verlopend. */
    public int $signedExpire = 0;

    /** Doelmap in de ImageKit Media Library voor uploads. */
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
