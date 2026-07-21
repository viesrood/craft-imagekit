<?php

declare(strict_types=1);

namespace viesrood\imagekit\utilities;

use Craft;
use craft\base\Utility;
use craft\web\View;
use viesrood\imagekit\Plugin;

/**
 * Control panel utility "ImageKit": upload an image to ImageKit and view/copy
 * the converted variant + the Media Library path for use in imagekit().
 */
class ConvertUtility extends Utility
{
    public static function id(): string
    {
        return 'imagekit';
    }

    public static function displayName(): string
    {
        return 'ImageKit';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@viesrood/imagekit/icon.svg') ?: null;
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('imagekit/utility', [
            'configured' => Plugin::getInstance()->getImagekit()->isConfigured(),
        ], View::TEMPLATE_MODE_CP);
    }
}
