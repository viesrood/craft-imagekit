<?php

declare(strict_types=1);

namespace viesrood\imagekit\imagetransforms;

use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\models\ImageTransform;
use viesrood\imagekit\Plugin;

/**
 * Native Craft image transformer that delegates transformations to ImageKit.io.
 *
 * Registered in Plugin::init() via
 * craft\services\ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS. Configure this
 * transformer per volume/filesystem (config key `transformer`) to route Craft's
 * built-in transform API (`asset.url({ width: 400 })`, named transforms, `{% ... %}`)
 * through ImageKit.
 *
 * How it works: the public (untransformed) asset URL serves as the source. If the
 * volume points at the ImageKit endpoint, that becomes a Media Library path;
 * otherwise the ImageKit web proxy handles the external origin URL. The transform
 * parameters are translated to ImageKit transformations by the shared service
 * (Imagekit::url()).
 */
class ImagekitTransformer extends Component implements ImageTransformerInterface
{
    /**
     * Craft crop modes -> ImageKit transformation options.
     *
     * - crop:      exact w&h, fills the frame and crops       (ImageKit default: maintain_ratio)
     * - fit:       fits within w&h, no cropping               (c-at_max)
     * - stretch:   forces exact w&h, ignores ratio            (c-force)
     * - letterbox: fits within w&h and pads to the exact box  (cm-pad_resize + background)
     */
    private const MODE_MAP = [
        'crop' => ['crop' => 'maintain_ratio'],
        'fit' => ['crop' => 'at_max'],
        'stretch' => ['crop' => 'force'],
        'letterbox' => ['cropMode' => 'pad_resize'],
    ];

    /**
     * Craft positions (`x-y`) -> ImageKit focus values. Only meaningful when cropping.
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
        $service = Plugin::getInstance()->getImagekit();

        // Focal-point-aware path: for a centered crop of an asset with a focal
        // point, route the Asset itself through the service so its focal-crop
        // logic applies. An explicit non-center transform position wins over
        // the focal point (matching Craft's transform semantics).
        if ($this->shouldUseFocalPoint($asset, $imageTransform)) {
            $options = $this->outputOptions($imageTransform) + [
                'mode' => 'crop',
                'width' => $imageTransform->width,
                'height' => $imageTransform->height,
            ];

            return $service->url($asset, $options);
        }

        // Use the untransformed public asset URL as the source (no recursion:
        // without a transform argument getUrl() does not invoke the transformer).
        $source = $asset->getUrl();

        if ($source === null || $source === '') {
            // Fall back to the volume path so a Media Library path still works.
            $source = $asset->getPath();
        }

        return $service->url($source, $this->transformOptions($imageTransform));
    }

    /**
     * The focal-crop path applies to centered crops with both dimensions set,
     * on assets that actually have a focal point.
     */
    private function shouldUseFocalPoint(Asset $asset, ImageTransform $transform): bool
    {
        if ($transform->mode !== 'crop' || !$transform->width || !$transform->height) {
            return false;
        }

        $position = $transform->position ?? 'center-center';
        if ($position !== '' && $position !== 'center-center') {
            return false;
        }

        return $asset->getHasFocalPoint();
    }

    /**
     * ImageKit URLs are purely derived from their source + parameters; there is
     * nothing local to invalidate. When an existing file's contents change,
     * ImageKit's cache purge API can be used (planned as an opt-in feature;
     * out of scope for this transformer for now).
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
        $options = $this->outputOptions($transform);

        if ($transform->width !== null) {
            $options['width'] = $transform->width;
        }
        if ($transform->height !== null) {
            $options['height'] = $transform->height;
        }

        // Crop mode.
        $options += self::MODE_MAP[$transform->mode] ?? self::MODE_MAP['crop'];

        // Pad color for letterbox.
        if ($transform->mode === 'letterbox' && !empty($transform->fill)) {
            $options['background'] = ltrim((string)$transform->fill, '#');
        }

        // Focus only applies to cropping modes.
        if (in_array($transform->mode, ['crop', 'letterbox'], true)) {
            $focus = self::FOCUS_MAP[$transform->position] ?? null;
            if ($focus !== null) {
                $options['focus'] = $focus;
            }
        }

        return $options;
    }

    /**
     * Output options shared by both URL paths: quality, format, and Craft's
     * interlace setting mapped to progressive JPEG output.
     *
     * @return array<string,mixed>
     */
    private function outputOptions(ImageTransform $transform): array
    {
        $options = [];

        if ($transform->quality !== null) {
            $options['quality'] = $transform->quality;
        }
        if ($transform->format !== null && $transform->format !== '') {
            $options['format'] = $transform->format;
        }

        // Interlace maps to progressive output. Only meaningful for JPEG; also
        // applied when the format is unspecified (the source may be a JPEG).
        $format = $options['format'] ?? null;
        if (($transform->interlace ?? 'none') !== 'none'
            && ($format === null || in_array($format, ['jpg', 'jpeg'], true))
        ) {
            $options['progressive'] = true;
        }

        return $options;
    }
}
