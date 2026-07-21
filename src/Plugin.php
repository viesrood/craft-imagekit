<?php

declare(strict_types=1);

namespace viesrood\imagekit;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\ImageTransforms;
use craft\services\Utilities;
use craft\web\View;
use viesrood\imagekit\imagetransforms\ImagekitTransformer;
use viesrood\imagekit\models\Settings;
use viesrood\imagekit\services\Imagekit as ImagekitService;
use viesrood\imagekit\utilities\ConvertUtility;
use viesrood\imagekit\web\twig\ImagekitExtension;
use yii\base\Event;

/**
 * ImageKit plugin.
 *
 * Transforms and optimizes images through ImageKit.io:
 * - a native Craft image transformer (`asset.url({ width: 400 })` via ImageKit);
 * - Twig helper `imagekit()` / filter for real-time transformation URLs;
 * - `imagekit_srcset()` for responsive srcset strings;
 * - a control panel utility to upload local files to ImageKit.
 *
 * @property-read ImagekitService $imagekit
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /**
     * Register the service as a component so Plugin::getInstance()->imagekit works.
     *
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'imagekit' => ImagekitService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register the Twig helper + filter.
        Craft::$app->onInit(function () {
            Craft::$app->getView()->registerTwigExtension(new ImagekitExtension());
        });

        // Make the native image transformer available (opt-in per volume/filesystem
        // via the `transformer` config key, or programmatically on an ImageTransform).
        Event::on(
            ImageTransforms::class,
            ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ImagekitTransformer::class;
            }
        );

        // Register the control panel utility.
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ConvertUtility::class;
            }
        );

        Craft::info('ImageKit plugin loaded', __METHOD__);
    }

    /**
     * Shortcut to the service.
     */
    public function getImagekit(): ImagekitService
    {
        /** @var ImagekitService $service */
        $service = $this->get('imagekit');

        return $service;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('imagekit/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }
}
