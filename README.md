# ImageKit Toolkit voor Craft CMS

Realtime beeldtransformatie en -optimalisatie via [ImageKit.io](https://imagekit.io) - resize,
formaat en kwaliteit op de ImageKit-CDN. Het origineel blijft ongewijzigd; varianten worden
per aanvraag gegenereerd en gecached.

De plugin biedt drie manieren om ImageKit te gebruiken:

1. Een **native Craft-image-transformer** - Crafts ingebouwde transform-API
   (`asset.url({ width: 400 })`, named transforms) levert ImageKit-URL's.
2. **Twig-helpers** `imagekit()` / `imagekit_srcset()` voor directe transformatie-URL's.
3. Een **CP-hulpprogramma** om bestanden naar de ImageKit Media Library te uploaden.

## Vereisten

Craft CMS 5.0.0+ en PHP 8.2+.

## Installatie

```bash
composer require viesrood/craft-imagekit
php craft plugin/install imagekit
```

Of installeer via **Settings -> Plugins** in de control panel.

## Configuratie

Zet in `.env`:

```
IMAGEKIT_PUBLIC_KEY=public_...
IMAGEKIT_PRIVATE_KEY=private_...
IMAGEKIT_URL_ENDPOINT=https://ik.imagekit.io/<imagekit_id>
```

Defaults (formaat, kwaliteit, ondertekenen, upload-map) zijn per omgeving aan te passen via
`config/imagekit.php` en zichtbaar onder **Settings -> Plugins -> ImageKit**.

## Native image-transformer

Stel ImageKit in als transformer op het filesystem/volume waarvan je de afbeeldingen door
ImageKit wilt laten serveren. Wijs de `Base URL` van dat filesystem naar je ImageKit-endpoint
(dan wordt de asset-URL een Media Library-pad), of laat 'm naar de bestaande origin wijzen
(dan gebruikt ImageKit de web-proxy op die origin). Daarna werkt Crafts eigen API via ImageKit:

```twig
<img src="{{ asset.one().url({ width: 400, height: 300, format: 'webp' }) }}" alt="">
```

Ondersteunde transform-parameters worden vertaald naar ImageKit: `width`, `height`, `quality`,
`format` en `mode` (`crop`/`fit`/`stretch`/`letterbox`, incl. `position` als focuspunt en
`fill` als padkleur bij letterbox).

> De transformer is opt-in per volume, zodat bestaande, lokaal gegenereerde transforms niet
> onbedoeld veranderen.

## Twig-helper

`imagekit(bron, opties)` bouwt een transformatie-URL. `bron` mag een **Media Library-pad**
(`/map/foto.jpg`) of een **bestaande publieke URL** (web-proxy) zijn.

```twig
{# als functie #}
<img src="{{ imagekit('/hero.jpg', { width: 800, format: 'auto', quality: 75 }) }}" alt="">

{# als filter, op een bestaande URL #}
<img src="{{ 'https://voorbeeld.nl/foto.jpg' | imagekit({ width: 400 }) }}" alt="">

{# responsive srcset #}
<img
  src="{{ imagekit('/hero.jpg', { width: 960 }) }}"
  srcset="{{ imagekit_srcset('/hero.jpg', [320, 640, 960, 1280]) }}"
  sizes="(max-width: 768px) 100vw, 960px"
  alt="">
```

**Opties** (vriendelijke naam -> ImageKit-parameter): `width`/`w`, `height`/`h`, `format`/`f`
(`auto`/`webp`/`avif`/`jpg`/`png`), `quality`/`q`, `crop`/`c`, `cropMode`/`cm`, `focus`/`fo`,
`aspectRatio`/`ar`, `dpr`, `blur`/`bl`, `radius`/`r`, `rotation`/`rt`, `background`/`bg`.
`format` en `quality` vallen terug op de defaults uit de instellingen.
`signed: true` (+ optioneel `expire: <seconden>`) ondertekent de URL (HMAC-SHA1).

`f-auto` levert automatisch WebP/AVIF aan browsers die dat ondersteunen, anders het origineel.

## Uploaden (CP-hulpprogramma)

**Utilities -> ImageKit**: upload een lokaal bestand (of plak een URL), kies
formaat/afmeting/kwaliteit, en krijg de omgezette URL + het Media Library-pad terug om in
`imagekit()` te gebruiken.

## Programmatisch (PHP)

```php
use viesrood\imagekit\Plugin;

$svc = Plugin::getInstance()->getImagekit();
$url = $svc->url('/hero.jpg', ['width' => 800, 'format' => 'webp']);
$res = $svc->upload('/pad/naar/foto.jpg');   // ['url','filePath','fileId','width','height','size']
```

## Licentie

[MIT](LICENSE.md).
