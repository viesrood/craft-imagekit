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
 * ImageKit-plugin.
 *
 * Zet afbeeldingen om/optimaliseert ze via ImageKit.io:
 * - een native Craft-image-transformer (`asset.url({ width: 400 })` via ImageKit);
 * - Twig-helper `imagekit()` / filter voor realtime transformatie-URL's;
 * - `imagekit_srcset()` voor responsive srcset-strings;
 * - een CP-hulpprogramma om lokale bestanden naar ImageKit te uploaden.
 *
 * @property-read ImagekitService $imagekit
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /**
     * Registreer de service als component, zodat Plugin::getInstance()->imagekit werkt.
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

        // Twig-helper + filter registreren.
        Craft::$app->onInit(function () {
            Craft::$app->getView()->registerTwigExtension(new ImagekitExtension());
        });

        // Native image-transformer beschikbaar maken (opt-in per volume/filesystem via de
        // `transformer`-config, of programmatisch op een ImageTransform).
        Event::on(
            ImageTransforms::class,
            ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ImagekitTransformer::class;
            }
        );

        // CP-hulpprogramma registreren.
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ConvertUtility::class;
            }
        );

        Craft::info('ImageKit-plugin geladen', __METHOD__);
    }

    /**
     * Snelkoppeling naar de service.
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
