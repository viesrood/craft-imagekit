<?php

declare(strict_types=1);

namespace viesrood\imagekit\tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use viesrood\imagekit\helpers\Transformation;

final class TransformationTest extends TestCase
{
    public function testMapsFriendlyAliasesToSdkKeys(): void
    {
        $result = Transformation::mapOptions([
            'w' => 400,
            'h' => 300,
            'f' => 'webp',
            'q' => 60,
            'flip' => 'h',
            'opacity' => 50,
            'zoom' => 1.5,
            'border' => '5_FF0000',
            'named' => 'thumb',
        ], null, null);

        $this->assertSame([
            'width' => '400',
            'height' => '300',
            'format' => 'webp',
            'quality' => '60',
            'fl' => 'h',
            'o' => '50',
            'z' => '1.5',
            'border' => '5_FF0000',
            'named' => 'thumb',
        ], $result);
    }

    public function testAppliesDefaultsOnlyWhenUnset(): void
    {
        $withDefaults = Transformation::mapOptions(['width' => 400], 'auto', 80);
        $this->assertSame('auto', $withDefaults['format']);
        $this->assertSame('80', $withDefaults['quality']);

        $overridden = Transformation::mapOptions(['format' => 'png', 'quality' => 20], 'auto', 80);
        $this->assertSame('png', $overridden['format']);
        $this->assertSame('20', $overridden['quality']);
    }

    public function testEmptyDefaultFormatIsNotApplied(): void
    {
        $result = Transformation::mapOptions(['width' => 100], '', null);

        $this->assertArrayNotHasKey('format', $result);
        $this->assertArrayNotHasKey('quality', $result);
    }

    public function testSkipsNullEmptyAndFalseValues(): void
    {
        $result = Transformation::mapOptions([
            'width' => null,
            'height' => '',
            'grayscale' => false,
            'blur' => 8,
        ], null, null);

        $this->assertSame(['blur' => '8'], $result);
    }

    public function testBareFlagEffectsEmitDashMarker(): void
    {
        $result = Transformation::mapOptions([
            'grayscale' => true,
            'contrast' => true,
            'sharpen' => true,
        ], null, null);

        $this->assertSame('-', $result['effectGray']);
        $this->assertSame('-', $result['effectContrast']);
        $this->assertSame('-', $result['effectSharpen']);
    }

    public function testValueFlagsEmitTrue(): void
    {
        $result = Transformation::mapOptions([
            'trim' => true,
            'progressive' => true,
            'lossless' => true,
            'original' => true,
        ], null, null);

        $this->assertSame('true', $result['trim']);
        $this->assertSame('true', $result['progressive']);
        $this->assertSame('true', $result['lossless']);
        $this->assertSame('true', $result['original']);
    }

    public function testFlagStyleOptionsAcceptExplicitValues(): void
    {
        $result = Transformation::mapOptions([
            'trim' => 5,
            'sharpen' => 10,
        ], null, null);

        $this->assertSame('5', $result['trim']);
        $this->assertSame('10', $result['effectSharpen']);
    }

    public function testUnknownKeysPassThroughVerbatim(): void
    {
        $result = Transformation::mapOptions([
            'effectGray' => '-',
            'e-genvar' => '-',
            'raw' => 'l-text,i-Hi,l-end',
        ], null, null);

        $this->assertSame('-', $result['effectGray']);
        $this->assertSame('-', $result['e-genvar']);
        $this->assertSame('l-text,i-Hi,l-end', $result['raw']);
    }

    public function testAiOptions(): void
    {
        $result = Transformation::mapOptions([
            'removeBackground' => true,
            'upscale' => true,
            'dropShadow' => 'az-215',
        ], null, null);

        $this->assertSame('-', $result['e-bgremove']);
        $this->assertSame('-', $result['e-upscale']);
        $this->assertSame('az-215', $result['e-dropshadow']);
    }

    public function testAiPromptOptionsAreEncoded(): void
    {
        $result = Transformation::mapOptions([
            'changeBackground' => 'snow road',
            'generativeFill' => 'blue sky',
        ], null, null);

        $this->assertSame('prompt-snow%20road', $result['e-changebg']);
        $this->assertSame('genfill-prompt-blue%20sky', $result['background']);

        $bare = Transformation::mapOptions(['generativeFill' => true], null, null);
        $this->assertSame('genfill', $bare['background']);
    }

    public function testCenteredFocalPointProducesSingleStep(): void
    {
        $steps = Transformation::focalCropSteps(300, 300, 1200, 800, 0.5, 0.51, ['format' => 'auto']);

        $this->assertCount(1, $steps);
        $this->assertSame([
            'format' => 'auto',
            'width' => '300',
            'height' => '300',
        ], $steps[0]);
    }

    public function testMissingFocalPointProducesSingleStep(): void
    {
        $steps = Transformation::focalCropSteps(300, 300, 1200, 800, null, null, []);

        $this->assertCount(1, $steps);
        $this->assertSame(['width' => '300', 'height' => '300'], $steps[0]);
    }

    public function testOffCenterFocalPointProducesScaleAndExtractSteps(): void
    {
        $steps = Transformation::focalCropSteps(300, 300, 1200, 800, 0.8, 0.3, ['format' => 'auto']);

        $this->assertCount(2, $steps);
        $this->assertSame([
            'width' => '450',
            'height' => '300',
            'crop' => 'force',
        ], $steps[0]);
        $this->assertSame([
            'cropMode' => 'extract',
            'x' => '150',
            'y' => '0',
            'width' => '300',
            'height' => '300',
            'format' => 'auto',
        ], $steps[1]);
    }

    public function testFocalCropClampsAtTheEdges(): void
    {
        $steps = Transformation::focalCropSteps(300, 300, 1200, 800, 1.0, 1.0, []);

        // Scaled to 450x300; a bottom-right focal point clamps to the maximum offset.
        $this->assertSame('150', $steps[1]['x']);
        $this->assertSame('0', $steps[1]['y']);
    }

    public function testImageOverlayLayer(): void
    {
        $layer = Transformation::buildLayer([
            'image' => '/logos/logo.png',
            'position' => 'bottom_right',
            'width' => 100,
            'opacity' => 60,
        ]);

        $this->assertSame('l-image,i-logos@@logo.png,lfo-bottom_right,w-100,o-60,l-end', $layer);
    }

    public function testTextOverlayLayerWithSimpleText(): void
    {
        $layer = Transformation::buildLayer([
            'text' => 'Hello World',
            'fontSize' => 24,
            'color' => '#1b1cf4',
            'padding' => 10,
        ]);

        $this->assertSame('l-text,i-Hello%20World,fs-24,co-1b1cf4,pa-10,l-end', $layer);
    }

    public function testTextOverlayLayerWithSpecialCharactersUsesBase64(): void
    {
        $layer = Transformation::buildLayer(['text' => 'Héllo & co']);

        $this->assertSame('l-text,ie-' . rawurlencode(base64_encode('Héllo & co')) . ',l-end', $layer);
    }

    public function testOverlayWithoutImageOrTextThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Transformation::buildLayer(['position' => 'center']);
    }
}
