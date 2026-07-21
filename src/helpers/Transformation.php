<?php

declare(strict_types=1);

namespace viesrood\imagekit\helpers;

use InvalidArgumentException;

/**
 * Pure transformation-mapping logic, decoupled from Craft and the ImageKit SDK.
 *
 * Everything in this class works on plain scalars and arrays so it can be unit
 * tested without a Craft runtime. The service layer (services\Imagekit) is
 * responsible for resolving assets, settings and SDK clients and delegates the
 * option mapping, focal-crop math and overlay building to this helper.
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
     * Keys the SDK does not know (e.g. `fl`, `o`, `z`) are passed through to
     * the URL verbatim by the SDK, which is also the documented escape hatch
     * for ImageKit parameters without a friendly alias.
     */
    public const OPTION_MAP = [
        // Sizing
        'width' => 'width',
        'w' => 'width',
        'height' => 'height',
        'h' => 'height',
        'aspectRatio' => 'aspectRatio',
        'ar' => 'aspectRatio',
        'dpr' => 'dpr',
        // Cropping & focus
        'crop' => 'crop',
        'c' => 'crop',
        'cropMode' => 'cropMode',
        'cm' => 'cropMode',
        'x' => 'x',
        'y' => 'y',
        'xc' => 'xc',
        'yc' => 'yc',
        'focus' => 'focus',
        'fo' => 'focus',
        'zoom' => 'z',
        'z' => 'z',
        // Output
        'format' => 'format',
        'f' => 'format',
        'quality' => 'quality',
        'q' => 'quality',
        'progressive' => 'progressive',
        'pr' => 'progressive',
        'lossless' => 'lossless',
        'lo' => 'lossless',
        'metadata' => 'metadata',
        'md' => 'metadata',
        'colorProfile' => 'colorProfile',
        'defaultImage' => 'defaultImage',
        'di' => 'defaultImage',
        'original' => 'original',
        'named' => 'named',
        'n' => 'named',
        // Effects & adjustments
        'blur' => 'blur',
        'bl' => 'blur',
        'radius' => 'radius',
        'r' => 'radius',
        'rotation' => 'rotation',
        'rt' => 'rotation',
        'rotate' => 'rotation',
        'flip' => 'fl',
        'fl' => 'fl',
        'opacity' => 'o',
        'background' => 'background',
        'bg' => 'background',
        'border' => 'border',
        'b' => 'border',
        'trim' => 'trim',
        't' => 'trim',
        'sharpen' => 'effectSharpen',
        'usm' => 'effectUSM',
        'contrast' => 'effectContrast',
        'grayscale' => 'effectGray',
        'shadow' => 'effectShadow',
        'gradient' => 'effectGradient',
    ];

    /**
     * SDK keys that are emitted as a bare parameter (no value) when the option
     * is `true`. The `'-'` marker makes the SDK emit only the key.
     *
     * @var string[]
     */
    private const BARE_FLAG_KEYS = [
        'effectGray',
        'effectContrast',
        'effectSharpen',
        'effectShadow',
        'effectGradient',
        'e-bgremove',
        'e-removedotbg',
        'e-dropshadow',
        'e-upscale',
        'e-retouch',
    ];

    /**
     * AI convenience options. These are metered, paid ImageKit add-ons (and
     * partly beta); the plugin only translates the friendly name to the raw
     * `e-*` parameter. `true` emits the bare parameter; a string value is
     * appended as configuration (e.g. dropShadow: 'az-215').
     */
    private const AI_OPTION_MAP = [
        'removeBackground' => 'e-bgremove',
        'removeBackgroundPro' => 'e-removedotbg',
        'dropShadow' => 'e-dropshadow',
        'upscale' => 'e-upscale',
        'retouch' => 'e-retouch',
    ];

    /**
     * Map friendly options to an SDK transformation step.
     *
     * Null, empty-string and `false` values are skipped. `true` values become
     * a bare parameter for flag-style keys and `'true'` for the rest (e.g.
     * `trim: true` -> `t-true`). The default format/quality are applied when
     * the options do not specify them explicitly.
     *
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    public static function mapOptions(array $options, ?string $defaultFormat, ?int $defaultQuality): array
    {
        $transformation = [];

        foreach ($options as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            // AI prompt options expand to their own parameter + encoded prompt.
            if ($key === 'changeBackground') {
                $transformation['e-changebg'] = 'prompt-' . rawurlencode((string)$value);
                continue;
            }
            if ($key === 'generativeFill') {
                $transformation['background'] = $value === true
                    ? 'genfill'
                    : 'genfill-prompt-' . rawurlencode((string)$value);
                continue;
            }

            $sdkKey = self::AI_OPTION_MAP[$key] ?? self::OPTION_MAP[$key] ?? $key;

            if ($value === true) {
                $transformation[$sdkKey] = in_array($sdkKey, self::BARE_FLAG_KEYS, true) ? '-' : 'true';
                continue;
            }

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

    /**
     * Compile a friendly overlay definition into a raw ImageKit layer string
     * (`l-image,...,l-end` / `l-text,...,l-end`).
     *
     * Image overlay: { image: 'logo.png', position: 'bottom_right', width: 100,
     * height: 50, x: 10, y: 10, opacity: 60, radius: 8 }.
     * Text overlay: { text: 'Hello', fontSize: 24, font: 'Open Sans',
     * color: '1b1cf4', padding: 10, background: 'FFFFFF', position: 'center',
     * width: 400, x: 10, y: 10, radius: 8 }.
     *
     * @param array<string,mixed> $overlay
     */
    public static function buildLayer(array $overlay): string
    {
        if (isset($overlay['image'])) {
            $parts = ['l-image', 'i-' . self::layerPath((string)$overlay['image'])];
            $paramMap = [
                'width' => 'w',
                'height' => 'h',
                'x' => 'lx',
                'y' => 'ly',
                'opacity' => 'o',
                'radius' => 'r',
            ];
        } elseif (isset($overlay['text'])) {
            $parts = ['l-text', self::encodeLayerText((string)$overlay['text'])];
            $paramMap = [
                'fontSize' => 'fs',
                'font' => 'ff',
                'fontFamily' => 'ff',
                'color' => 'co',
                'padding' => 'pa',
                'background' => 'bg',
                'width' => 'w',
                'x' => 'lx',
                'y' => 'ly',
                'radius' => 'r',
            ];
        } else {
            throw new InvalidArgumentException('An overlay needs an "image" or "text" key.');
        }

        if (isset($overlay['position']) && $overlay['position'] !== '') {
            $parts[] = 'lfo-' . $overlay['position'];
        }

        foreach ($paramMap as $key => $urlCode) {
            $value = $overlay[$key] ?? null;
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (in_array($key, ['color', 'background'], true)) {
                $value = ltrim((string)$value, '#');
            }
            $parts[] = $urlCode . '-' . $value;
        }

        $parts[] = 'l-end';

        return implode(',', $parts);
    }

    /**
     * An overlay image path: relative to the Media Library root, with path
     * separators encoded the way ImageKit expects inside a layer (`@@`).
     */
    private static function layerPath(string $path): string
    {
        return str_replace('/', '@@', trim($path, '/'));
    }

    /**
     * Encode overlay text: plain `i-` for simple text, base64 `ie-` (URL-safe)
     * for anything with special characters.
     */
    private static function encodeLayerText(string $text): string
    {
        if (preg_match('/^[A-Za-z0-9 ._-]*$/', $text)) {
            return 'i-' . str_replace(' ', '%20', $text);
        }

        return 'ie-' . rawurlencode(base64_encode($text));
    }
}
