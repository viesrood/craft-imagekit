# ImageKit Toolkit for Craft CMS

Real-time image transformation and optimization through [ImageKit.io](https://imagekit.io) -
resizing, format and quality on the ImageKit CDN. The original stays untouched; variants are
generated on request and cached.

The plugin offers three ways to use ImageKit:

1. A **native Craft image transformer** - Craft's built-in transform API
   (`asset.url({ width: 400 })`, named transforms) returns ImageKit URLs.
2. **Twig helpers** `imagekit()` / `imagekit_srcset()` / `imagekit_img()` for direct
   transformation URLs and complete responsive `<img>` tags.
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

All settings can be overridden per environment via `config/imagekit.php` and are visible under
**Settings -> Plugins -> ImageKit Toolkit**:

```php
<?php

use craft\helpers\App;

return [
    // Credentials (usually left pointing at the env vars).
    'publicKey'   => App::env('IMAGEKIT_PUBLIC_KEY')   ?: '$IMAGEKIT_PUBLIC_KEY',
    'privateKey'  => App::env('IMAGEKIT_PRIVATE_KEY')  ?: '$IMAGEKIT_PRIVATE_KEY',
    'urlEndpoint' => App::env('IMAGEKIT_URL_ENDPOINT') ?: '$IMAGEKIT_URL_ENDPOINT',

    // Output defaults, applied when a URL does not specify them.
    'defaultFormat'  => 'auto',
    'defaultQuality' => 80,

    // URL signing.
    'signUrls'     => false,
    'signedExpire' => 0,

    // Responsive images.
    'defaultSrcsetWidths' => [400, 800, 1200, 1600],
    'useNativeInDevMode'  => false,

    // Uploads (control panel utility).
    'uploadFolder'            => '/uploads',
    'uploadAllowedExtensions' => ['avif', 'gif', 'heic', 'jpeg', 'jpg', 'png', 'svg', 'webp'],
    'uploadMaxFileSize'       => 0,
];
```

| Setting | Type | Default | Description |
|---|---|---|---|
| `publicKey` | string | `$IMAGEKIT_PUBLIC_KEY` | ImageKit public API key (env var reference allowed). |
| `privateKey` | string | `$IMAGEKIT_PRIVATE_KEY` | ImageKit private API key - server-side only. |
| `urlEndpoint` | string | `$IMAGEKIT_URL_ENDPOINT` | Your URL endpoint, e.g. `https://ik.imagekit.io/<id>`. |
| `defaultFormat` | string | `'auto'` | Applied when no `format` option is given (`auto`/`webp`/`avif`/`jpg`/`png`/`''`). |
| `defaultQuality` | int\|null | `80` | Applied when no `quality` option is given (1-100); `null` = do not set. |
| `signUrls` | bool | `false` | Sign every URL by default (HMAC-SHA1). |
| `signedExpire` | int | `0` | Signed-URL validity in seconds; `0` = non-expiring. |
| `defaultSrcsetWidths` | int[] | `[400, 800, 1200, 1600]` | Widths used by `imagekit_srcset()`/`imagekit_img()` when none are given. |
| `useNativeInDevMode` | bool | `false` | Serve untransformed asset URLs for Craft assets while devMode is on. |
| `uploadFolder` | string | `'/uploads'` | Target Media Library folder for utility uploads. |
| `uploadAllowedExtensions` | string[] | images | Extension allowlist for utility uploads. |
| `uploadMaxFileSize` | int | `0` | Upload size cap in bytes; `0` = Craft's `maxUploadFileSize`. |

## Native image transformer

Set ImageKit as the transformer on the filesystem/volume whose images you want ImageKit to
serve. Point that filesystem's `Base URL` at your ImageKit endpoint (so the asset URL becomes a
Media Library path), or leave it pointing at the existing origin (so ImageKit uses its web proxy
against that origin). Craft's own API then runs through ImageKit:

```twig
<img src="{{ asset.one().url({ width: 400, height: 300, format: 'webp' }) }}" alt="">
```

Supported transform parameters are translated to ImageKit: `width`, `height`, `quality`,
`format`, `mode` (`crop`/`fit`/`stretch`/`letterbox`, including `position` as the focus point
and `fill` as the pad color for letterbox) and `interlace` (mapped to progressive JPEG output).
A centered `crop` of an asset **with a focal point** automatically uses the focal-point-aware
crop path (an explicit non-center `position` on the transform still wins).

> The transformer is opt-in per volume, so existing, locally generated transforms are not
> changed unintentionally.

## Twig helpers

### `imagekit(source, options)`

Builds a transformation URL. `source` may be a **Craft asset**, a **Media Library path**
(`/folder/photo.jpg`) or an existing **public URL** (web proxy). Also available as a filter.

```twig
{# as a function, on a Media Library path #}
<img src="{{ imagekit('/hero.jpg', { width: 800, format: 'auto', quality: 75 }) }}" alt="">

{# on a Craft asset - focal-point-aware crop #}
<img src="{{ imagekit(entry.image.one(), { mode: 'crop', width: 720, height: 480, format: 'webp' }) }}" alt="">

{# as a filter, on an existing URL #}
<img src="{{ 'https://example.com/photo.jpg' | imagekit({ width: 400 }) }}" alt="">
```

### `imagekit_img(asset, options)`

Renders a complete responsive `<img>` tag for a Craft asset: `src`, `srcset` (from
`defaultSrcsetWidths` unless overridden), `sizes`, intrinsic `width`/`height` (target-ratio
aware), `alt` (from the asset), `loading="lazy"` and `decoding="async"`. When the plugin is not
configured - or `useNativeInDevMode` is on in devMode - it automatically falls back to the plain
asset URL, so local previews keep working without any template logic.

```twig
{{ imagekit_img(entry.image.one(), {
  sizes: '(max-width: 767px) 100vw, 50vw',
  class: 'block h-auto w-full rounded',
}) }}

{# with a crop and custom widths #}
{{ imagekit_img(entry.image.one(), {
  mode: 'crop', width: 800, height: 450,
  srcset: [400, 800, 1200],
  sizes: '100vw',
}) }}
```

Tag options (everything else is passed on as transformation options): `srcset` (widths array,
or `false` to disable), `sizes` (default `'100vw'`), `alt`, `loading` (default `'lazy'`),
`decoding` (default `'async'`), `class`, `attributes` (extra attribute map, merged last).

### `imagekit_srcset(source, widths = null, options = [])`

Builds a srcset string. `widths` falls back to the `defaultSrcsetWidths` setting.

```twig
<img
  src="{{ imagekit('/hero.jpg', { width: 960 }) }}"
  srcset="{{ imagekit_srcset('/hero.jpg', [320, 640, 960, 1280]) }}"
  sizes="(max-width: 768px) 100vw, 960px"
  alt="">
```

### `imagekit_configured()`

Returns whether the plugin has a complete configuration - handy for template fallbacks:

```twig
{% if imagekit_configured() %}...{% endif %}
```

## Transformation options

Friendly name -> ImageKit parameter. `format` and `quality` fall back to the defaults from the
settings.

**Sizing:** `width`/`w`, `height`/`h`, `aspectRatio`/`ar` (e.g. `'16-9'`), `dpr`.

**Cropping & focus:** `crop`/`c` (`maintain_ratio`/`force`/`at_max`/`at_least`), `cropMode`/`cm`
(`pad_resize`/`extract`/`pad_extract`), `x`, `y`, `xc`, `yc`, `focus`/`fo` (`auto`, `face`,
`center`, `top_left`, ... or an object like `fo-dog`), `zoom`/`z`.

**Output:** `format`/`f` (`auto`/`webp`/`avif`/`jpg`/`png`), `quality`/`q`, `progressive`/`pr`,
`lossless`/`lo`, `metadata`/`md`, `colorProfile`, `defaultImage`/`di`, `original`,
`named`/`n` (named transformation from your ImageKit dashboard).

**Effects:** `blur`/`bl`, `radius`/`r` (number or `'max'`), `rotation`/`rt`, `flip`/`fl`
(`h`/`v`/`h_v`), `opacity`, `background`/`bg`, `border`/`b` (`'5_FF0000'`), `trim`/`t`
(`true` or 1-99), `sharpen` (`true` or amount), `usm`, `contrast` (`true`), `grayscale`
(`true`), `shadow`, `gradient`.

Boolean handling: `true` emits the bare parameter for flag-style effects (`grayscale: true` ->
`e-grayscale`) and `-true` for value flags (`trim: true` -> `t-true`); `false`/`null` skip the
parameter.

**AI transformations** (metered, paid ImageKit add-ons - partly beta; consider restricting
unsigned AI requests in your ImageKit dashboard): `removeBackground` (`e-bgremove`),
`removeBackgroundPro` (`e-removedotbg`), `dropShadow` (`true` or a config string like
`'az-215'`), `upscale`, `retouch`, `changeBackground: 'prompt text'`,
`generativeFill` (`true` or a prompt; combine with `width`/`height` + `cropMode: 'pad_resize'`).

```twig
{{ imagekit(product.image.one(), { width: 600, removeBackground: true, dropShadow: true }) }}
```

**Chained transformations** - the `chain` option appends extra transformation steps, applied
left-to-right; the format/quality defaults move to the last step:

```twig
{{ imagekit(asset, { mode: 'crop', width: 600, height: 600, chain: [{ blur: 20 }, { grayscale: true }] }) }}
```

**Overlays** - the `overlay` option accepts one or more image/text layers:

```twig
{# watermark, bottom right #}
{{ imagekit(asset, { width: 1200, overlay: { image: '/logos/logo.png', position: 'bottom_right', width: 160, opacity: 60 } }) }}

{# text banner #}
{{ imagekit(asset, { width: 1200, overlay: { text: 'Hello', fontSize: 48, color: 'FFFFFF', background: '1b1cf4', padding: 20, position: 'south' } }) }}
```

Image overlay keys: `image` (Media Library path), `position`, `width`, `height`, `x`, `y`,
`opacity`, `radius`. Text overlay keys: `text`, `fontSize`, `font`, `color`, `padding`,
`background`, `width`, `x`, `y`, `radius`, plus `position`.

**Escape hatch:** unknown option keys are passed through to the URL verbatim, so any ImageKit
parameter without a friendly alias still works - e.g. `{ 'e-genvar': '-' }` or
`{ raw: 'l-image,i-logo.png,l-end' }`.

**URL options:** `signed: true` (plus an optional `expire: <seconds>`) signs the URL
(HMAC-SHA1); both default to the `signUrls`/`signedExpire` settings.

## Responsive images

`imagekit_img()` is the short path: it derives `src` and `srcset` from `defaultSrcsetWidths`,
adds intrinsic dimensions and lazy loading, and handles the local fallback. Before:

```twig
{% set configured = craft.app.plugins.getPlugin('imagekit').imagekit.isConfigured %}
{% set useIk = configured and not craft.app.config.general.devMode %}
<img
  src="{{ useIk ? imagekit(img.url, { width: 1200 }) : img.url }}"
  {% if useIk %}srcset="{{ imagekit_srcset(img.url, [400, 800, 1200, 1600]) }}" sizes="{{ sizes }}"{% endif %}
  alt="{{ img.alt ?? img.title }}"
  {% if img.width and img.height %}width="{{ img.width }}" height="{{ img.height }}"{% endif %}
  loading="lazy" decoding="async" class="block h-auto w-full rounded">
```

After:

```twig
{{ imagekit_img(img, { sizes: sizes, class: 'block h-auto w-full rounded' }) }}
```

Why `useNativeInDevMode`: ImageKit fetches images from your origin, and local development
origins (e.g. `*.ddev.site`) are not reachable from ImageKit's servers. With the setting on,
Craft assets resolve to their plain local URLs while devMode is on, so previews keep working;
production output is unaffected. String paths/URLs are exempt so utility/demo pages keep
producing real ImageKit URLs locally.

## Security

- **Signed URLs + web proxy.** If you attach a Web Proxy origin to your URL endpoint, anyone
  can transform arbitrary public URLs on your account - an unsigned web proxy is an open proxy.
  Enable `signUrls` (or ImageKit's "Restrict unsigned URLs" dashboard setting, which can also
  restrict only the metered AI transformations). Signing requires the private key and happens
  server-side only.
- **Upload permission.** The upload endpoint behind the utility requires the `utility:imagekit`
  permission (**User permissions -> Utilities -> ImageKit**; admins always pass).
- **Upload validation.** Uploads are validated server-side against `uploadAllowedExtensions`,
  a real (finfo) MIME sniff, and `uploadMaxFileSize`.
- **Unconfigured behavior.** Without a URL endpoint the plugin never emits placeholder URLs:
  asset sources fall back to native Craft URLs, absolute URLs are returned untouched, Media
  Library paths return `''`, and a warning is logged once per request. Requesting a signed URL
  without a private key logs an error and returns the unsigned URL.

## Uploading (control panel utility)

**Utilities -> ImageKit**: upload a local file (or paste a URL), choose
format/dimensions/quality, and get the transformed URL plus the Media Library path to use in
`imagekit()`.

## Programmatic (PHP)

```php
use viesrood\imagekit\Plugin;

$svc = Plugin::getInstance()->getImagekit();
$url = $svc->url('/hero.jpg', ['width' => 800, 'format' => 'webp']);
$img = $svc->imgTag($asset, ['sizes' => '50vw']);
$res = $svc->upload('/path/to/photo.jpg');   // ['url','filePath','fileId','width','height','size']
```

## Roadmap

Planned as opt-in features: cache purge on asset replacement (ImageKit purge API) and a signed
authentication endpoint for client-side uploads.

## License

[MIT](LICENSE.md).
