<?php

declare(strict_types=1);

namespace viesrood\imagekit\web\twig;

use craft\elements\Asset;
use viesrood\imagekit\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-helpers voor ImageKit.
 *
 * De bron mag een string (Media Library-pad of externe URL) of een Craft-{@see Asset} zijn.
 * Bij een asset zijn crops focuspunt-bewust en begrijpt hij de opties `mode: 'crop'|'fit'`,
 * `width` en `height`.
 *
 * Functie:  {{ imagekit('/foto.jpg', { width: 800, format: 'auto' }) }}
 * Asset:    {{ imagekit(entry.image.one(), { mode: 'crop', width: 720, height: 480 }) }}
 * Filter:   {{ 'https://voorbeeld.nl/foto.jpg' | imagekit({ width: 800 }) }}
 * Srcset:   <img srcset="{{ imagekit_srcset('/foto.jpg', [400, 800, 1200]) }}">
 */
class ImagekitExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('imagekit', [$this, 'url']),
            new TwigFunction('imagekit_srcset', [$this, 'srcset']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('imagekit', [$this, 'url']),
        ];
    }

    /**
     * @param Asset|string|null $source
     * @param array<string,mixed> $options
     */
    public function url(Asset|string|null $source, array $options = []): string
    {
        return Plugin::getInstance()->getImagekit()->url($source, $options);
    }

    /**
     * @param Asset|string $source
     * @param int[] $widths
     * @param array<string,mixed> $options
     */
    public function srcset(Asset|string $source, array $widths, array $options = []): string
    {
        return Plugin::getInstance()->getImagekit()->srcset($source, $widths, $options);
    }
}
