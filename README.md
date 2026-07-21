# ImageKit Toolkit for Craft CMS

Real-time image transformation and optimization through [ImageKit.io](https://imagekit.io) -
resizing, format and quality on the ImageKit CDN. The original stays untouched; variants are
generated on request and cached.

The plugin offers three ways to use ImageKit:

1. A **native Craft image transformer** - Craft's built-in transform API
   (`asset.url({ width: 400 })`, named transforms) returns ImageKit URLs.
2. **Twig helpers** `imagekit()` / `imagekit_srcset()` for direct transformation URLs.
3. A **control panel utility** to upload files to the ImageKit Media Library.

## Requirements

Craft CMS 5.0.0+ and PHP 8.2+.

## Installation

```bash
composer require viesrood/craft-imagekit
php craft plugin/install imagekit
```

Or install it from **Settings -> Plugins** in the control panel.

## Configuration

Add to your `.env`:

```
IMAGEKIT_PUBLIC_KEY=public_...
IMAGEKIT_PRIVATE_KEY=private_...
IMAGEKIT_URL_ENDPOINT=https://ik.imagekit.io/<imagekit_id>
```

Defaults (format, quality, signing, upload folder) can be overridden per environment via
`config/imagekit.php` and are visible under **Settings -> Plugins -> ImageKit Toolkit**.

## Native image transformer

Set ImageKit as the transformer on the filesystem/volume whose images you want ImageKit to
serve. Point that filesystem's `Base URL` at your ImageKit endpoint (so the asset URL becomes a
Media Library path), or leave it pointing at the existing origin (so ImageKit uses its web proxy
against that origin). Craft's own API then runs through ImageKit:

```twig
<img src="{{ asset.one().url({ width: 400, height: 300, format: 'webp' }) }}" alt="">
```

Supported transform parameters are translated to ImageKit: `width`, `height`, `quality`,
`format` and `mode` (`crop`/`fit`/`stretch`/`letterbox`, including `position` as the focus point
and `fill` as the pad color for letterbox).

> The transformer is opt-in per volume, so existing, locally generated transforms are not
> changed unintentionally.

## Twig helper

`imagekit(source, options)` builds a transformation URL. `source` may be a **Media Library path**
(`/folder/photo.jpg`) or an existing **public URL** (web proxy).

```twig
{# as a function #}
<img src="{{ imagekit('/hero.jpg', { width: 800, format: 'auto', quality: 75 }) }}" alt="">

{# as a filter, on an existing URL #}
<img src="{{ 'https://example.com/photo.jpg' | imagekit({ width: 400 }) }}" alt="">

{# responsive srcset #}
<img
  src="{{ imagekit('/hero.jpg', { width: 960 }) }}"
  srcset="{{ imagekit_srcset('/hero.jpg', [320, 640, 960, 1280]) }}"
  sizes="(max-width: 768px) 100vw, 960px"
  alt="">
```

**Options** (friendly name -> ImageKit parameter): `width`/`w`, `height`/`h`, `format`/`f`
(`auto`/`webp`/`avif`/`jpg`/`png`), `quality`/`q`, `crop`/`c`, `cropMode`/`cm`, `focus`/`fo`,
`aspectRatio`/`ar`, `dpr`, `blur`/`bl`, `radius`/`r`, `rotation`/`rt`, `background`/`bg`.
`format` and `quality` fall back to the defaults from the settings.
`signed: true` (plus an optional `expire: <seconds>`) signs the URL (HMAC-SHA1).

`f-auto` automatically serves WebP/AVIF to browsers that support it, otherwise the original.

## Uploading (control panel utility)

**Utilities -> ImageKit Toolkit**: upload a local file (or paste a URL), choose
format/dimensions/quality, and get the transformed URL plus the Media Library path to use in
`imagekit()`.

## Programmatic (PHP)

```php
use viesrood\imagekit\Plugin;

$svc = Plugin::getInstance()->getImagekit();
$url = $svc->url('/hero.jpg', ['width' => 800, 'format' => 'webp']);
$res = $svc->upload('/path/to/photo.jpg');   // ['url','filePath','fileId','width','height','size']
```

## License

[MIT](LICENSE.md).
