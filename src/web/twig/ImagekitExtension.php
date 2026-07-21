<?php

declare(strict_types=1);

namespace viesrood\imagekit\web\twig;

use viesrood\imagekit\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-helpers voor ImageKit.
 *
 * Functie:  {{ imagekit('/foto.jpg', { width: 800, format: 'auto' }) }}
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
     * @param array<string,mixed> $options
     */
    public function url(string $source, array $options = []): string
    {
        return Plugin::getInstance()->getImagekit()->url($source, $options);
    }

    /**
     * @param int[] $widths
     * @param array<string,mixed> $options
     */
    public function srcset(string $source, array $widths, array $options = []): string
    {
        return Plugin::getInstance()->getImagekit()->srcset($source, $widths, $options);
    }
}
