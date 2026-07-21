<?php

declare(strict_types=1);

namespace viesrood\imagekit\imagetransforms;

use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\models\ImageTransform;
use viesrood\imagekit\Plugin;

/**
 * Native Craft-image-transformer die transformaties uitbesteedt aan ImageKit.io.
 *
 * Registratie gebeurt in Plugin::init() via
 * craft\services\ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS. Stel deze transformer
 * per volume/filesystem in (config-key `transformer`) om de ingebouwde Craft-transform-API
 * (`asset.url({ width: 400 })`, named transforms, `{% ... %}`) via ImageKit te laten lopen.
 *
 * Werking: de publieke (niet-getransformeerde) asset-URL dient als bron. Wijst het volume
 * naar het ImageKit-endpoint, dan wordt dat een Media Library-pad; anders behandelt de
 * ImageKit web-proxy de externe origin-URL. De transform-parameters worden vertaald naar
 * ImageKit-transformaties door de gedeelde service (Imagekit::url()).
 */
class ImagekitTransformer extends Component implements ImageTransformerInterface
{
    /**
     * Craft-crop-modes -> ImageKit-transformatieopties.
     *
     * - crop:      exacte w&h, vult het kader en snijdt bij  (ImageKit default: maintain_ratio)
     * - fit:       past binnen w&h, geen bijsnijden           (c-at_max)
     * - stretch:   forceert exacte w&h, negeert ratio         (c-force)
     * - letterbox: past binnen w&h en padt tot exact kader    (cm-pad_resize + background)
     */
    private const MODE_MAP = [
        'crop' => ['crop' => 'maintain_ratio'],
        'fit' => ['crop' => 'at_max'],
        'stretch' => ['crop' => 'force'],
        'letterbox' => ['cropMode' => 'pad_resize'],
    ];

    /**
     * Craft-posities (`x-y`) -> ImageKit-focuswaarden. Alleen zinvol bij bijsnijden.
     */
    private const FOCUS_MAP = [
        'top-left' => 'top_left',
        'top-center' => 'top',
        'top-right' => 'top_right',
        'center-left' => 'left',
        'center-center' => 'center',
        'center-right' => 'right',
        'bottom-left' => 'bottom_left',
        'bottom-center' => 'bottom',
        'bottom-right' => 'bottom_right',
    ];

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        // Niet-getransformeerde publieke URL van de asset als bron (geen recursie: zonder
        // transform-argument roept getUrl() de transformer niet aan).
        $source = $asset->getUrl();

        if ($source === null || $source === '') {
            // Val terug op het volume-pad zodat een Media Library-pad alsnog werkt.
            $source = $asset->getPath();
        }

        return Plugin::getInstance()->getImagekit()->url($source, $this->transformOptions($imageTransform));
    }

    /**
     * ImageKit-URL's zijn puur afgeleid van hun bron + parameters; er is niets lokaals te
     * invalideren. Bij inhoudelijke wijzigingen van een bestaand bestand verzorgt ImageKit
     * zelf cache-purge (buiten de scope van deze transformer).
     */
    public function invalidateAssetTransforms(Asset $asset): void
    {
        // No-op.
    }

    /**
     * @return array<string,mixed>
     */
    private function transformOptions(ImageTransform $transform): array
    {
        $options = [];

        if ($transform->width !== null) {
            $options['width'] = $transform->width;
        }
        if ($transform->height !== null) {
            $options['height'] = $transform->height;
        }
        if ($transform->quality !== null) {
            $options['quality'] = $transform->quality;
        }
        if ($transform->format !== null && $transform->format !== '') {
            $options['format'] = $transform->format;
        }

        // Crop-mode.
        $options += self::MODE_MAP[$transform->mode] ?? self::MODE_MAP['crop'];

        // Padkleur voor letterbox.
        if ($transform->mode === 'letterbox' && !empty($transform->fill)) {
            $options['background'] = ltrim((string)$transform->fill, '#');
        }

        // Focus alleen bij bijsnijdende modes.
        if (in_array($transform->mode, ['crop', 'letterbox'], true)) {
            $focus = self::FOCUS_MAP[$transform->position] ?? null;
            if ($focus !== null) {
                $options['focus'] = $focus;
            }
        }

        return $options;
    }
}
