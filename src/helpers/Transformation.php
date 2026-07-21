<?php

declare(strict_types=1);

namespace viesrood\imagekit\helpers;

/**
 * Pure transformation-mapping logic, decoupled from Craft and the ImageKit SDK.
 *
 * Everything in this class works on plain scalars and arrays so it can be unit
 * tested without a Craft runtime. The service layer (services\Imagekit) is
 * responsible for resolving assets, settings and SDK clients and delegates the
 * option mapping and focal-crop math to this helper.
 */
final class Transformation
{
    /**
     * A focal point is treated as "centered" (and therefore ignored) when both
     * coordinates deviate less than this fraction from the exact center (0.5).
     */
    public const FOCAL_POINT_THRESHOLD = 0.02;

    /**
     * Map of friendly option names to SDK transformation keys.
     *
     * Keys not present in this map are passed through to the SDK verbatim; the
     * SDK in turn passes unknown keys straight into the URL, which is the
     * documented escape hatch for ImageKit parameters without a friendly alias.
     */
    public const OPTION_MAP = [
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
     * Map friendly options to an SDK transformation step.
     *
     * Null and empty-string values are skipped. The default format/quality are
     * applied when the options do not specify them explicitly.
     *
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function mapOptions(array $options, ?string $defaultFormat, ?int $defaultQuality): array
    {
        $transformation = [];

        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $sdkKey = self::OPTION_MAP[$key] ?? $key;
            $transformation[$sdkKey] = (string)$value;
        }

        if (!isset($transformation['format']) && $defaultFormat !== null && $defaultFormat !== '') {
            $transformation['format'] = $defaultFormat;
        }
        if (!isset($transformation['quality']) && $defaultQuality !== null) {
            $transformation['quality'] = (string)$defaultQuality;
        }

        return $transformation;
    }

    /**
     * Build the transformation steps for a focal-point-aware crop to exactly
     * $targetW x $targetH.
     *
     * Without a (relevant) focal point this is a plain fill-crop (ImageKit's
     * default maintain_ratio, centered). With an off-center focal point it
     * becomes a two-step transform: first scale to cover (c-force), then
     * cm-extract around the focal point.
     *
     * @param float|null $fpX Focal point X as a 0..1 fraction, or null when absent.
     * @param float|null $fpY Focal point Y as a 0..1 fraction, or null when absent.
     * @param array<string,string> $output Already-mapped output options (quality/format/...).
     * @return array<int,array<string,string>>
     */
    public static function focalCropSteps(
        int $targetW,
        int $targetH,
        int $origW,
        int $origH,
        ?float $fpX,
        ?float $fpY,
        array $output,
    ): array {
        $hasFocalPoint = $fpX !== null && $fpY !== null && $origW && $origH
            && (abs($fpX - 0.5) > self::FOCAL_POINT_THRESHOLD || abs($fpY - 0.5) > self::FOCAL_POINT_THRESHOLD);

        if (!$hasFocalPoint) {
            $t = $output;
            $t['width'] = (string)$targetW;
            $t['height'] = (string)$targetH;
            return [$t];
        }

        $scale = max($targetW / $origW, $targetH / $origH);
        $scaledW = (int)round($origW * $scale);
        $scaledH = (int)round($origH * $scale);

        $focalX = $fpX * $scaledW;
        $focalY = $fpY * $scaledH;

        $cropX = (int)max(0, min($scaledW - $targetW, round($focalX - $targetW / 2)));
        $cropY = (int)max(0, min($scaledH - $targetH, round($focalY - $targetH / 2)));

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
}
