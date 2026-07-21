# Release Notes for ImageKit Toolkit

## 1.2.0 - 2026-07-21

### Added

- `imagekit_img()` Twig function: renders a complete responsive `<img>` tag (src, srcset,
  sizes, intrinsic width/height, alt, lazy loading, async decoding) with an automatic fallback
  to the plain asset URL when the plugin is unconfigured or the devMode fallback is active.
- `imagekit_configured()` Twig function.
- The full ImageKit option vocabulary: `flip`, `opacity`, `zoom`, `xc`/`yc`, `border`, `trim`,
  `progressive`, `lossless`, `metadata`, `colorProfile`, `defaultImage`, `named` transforms,
  `original`, and the `sharpen`/`usm`/`contrast`/`grayscale`/`shadow`/`gradient` effects, with
  boolean flag handling (`grayscale: true` -> `e-grayscale`, `trim: true` -> `t-true`).
- AI transformation conveniences (metered ImageKit add-ons): `removeBackground`,
  `removeBackgroundPro`, `dropShadow`, `upscale`, `retouch`, `changeBackground` and
  `generativeFill`.
- `chain` option: extra chained transformation steps as an array of option maps.
- `proxy` option for asset sources: use the full asset URL (ImageKit web proxy) instead of an
  endpoint-relative path.
- `overlay` option: one or more image/text overlay layers from a friendly definition.
- Native transformer: focal-point-aware centered crops (an explicit non-center `position`
  still wins) and `interlace` mapped to progressive JPEG output.
- New settings: `defaultSrcsetWidths`, `useNativeInDevMode`, `uploadAllowedExtensions`,
  `uploadMaxFileSize`; `signedExpire` is now editable in the control panel.
- A PHPUnit test suite for the transformation mapping and a GitHub Actions CI workflow.

### Changed

- `imagekit_srcset()` widths are now optional and fall back to the `defaultSrcsetWidths`
  setting.
- The entire codebase (comments, exceptions, log messages) is now English.
- Settings are validated (`defaultQuality` 1-100, `defaultFormat` whitelist, non-negative
  `signedExpire`).

### Security

- The upload endpoint now requires the `utility:imagekit` permission instead of accepting any
  logged-in control panel user.
- Uploads are validated server-side: extension allowlist, finfo-based MIME sniff and a size
  cap; source URLs must be http(s) URLs or Media Library paths.
- Unexpected errors no longer leak raw exception messages to the client; they are logged and
  answered with a generic message.
- The plugin no longer emits placeholder-endpoint URLs when unconfigured: asset sources fall
  back to native Craft URLs, absolute URLs are returned untouched, and a warning is logged.
- Requesting a signed URL without a configured private key now logs an error and returns an
  unsigned URL instead of a silently invalid signature.

## 1.1.2 - 2026-07-21

### Changed

- Re-release of 1.1.1 to work around a Plugin Store packaging hiccup (1.1.1 was picked up by
  the plugin API but never packaged into the Composer repository). No code changes from 1.1.1.

## 1.1.1 - 2026-07-21

### Fixed

- Asset sources no longer throw a `TypeError` when the URL endpoint is not configured: an
  undefined `IMAGEKIT_URL_ENDPOINT` (where `App::parseEnv()` returns `null`, not `''`) now
  correctly triggers the native Craft transform fallback instead of `rtrim(null)`.

## 1.1.0 - 2026-07-21

### Added

- The `imagekit()` Twig helper (function + filter) and `imagekit_srcset()` now accept a Craft
  `Asset` as the source, in addition to a string path/URL.
- Focal-point-aware cropping for asset sources: a convenience `mode` option (`crop`/`fit`) plus
  `width`/`height`. In `crop` mode a non-centered `asset.focalPoint` produces a chained
  `c-force` -> `cm-extract` transform around the focal point; otherwise a centered crop.
- Asset sources return `''` for a `null` asset and fall back to a native Craft transform URL when
  the URL endpoint is not configured.
- `x` and `y` transformation options (for manual extract crops).

## 1.0.0 - 2026-07-21

### Added

- Initial release.
- Native Craft image transformer: registers ImageKit as a transformer via
  `craft\services\ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS`, so Craft's built-in
  transform API (`asset.url({ width: 400 })`) can return ImageKit URLs.
- Twig helpers `imagekit()` (function + filter) and `imagekit_srcset()` for real-time
  transformation URLs on a Media Library path or an existing (external) URL (web proxy).
- Optional signed URLs with a configurable expiry.
- Control panel utility to upload a local file or URL to the ImageKit Media Library and get the
  transformed URL plus the Media Library path back.
- Settings via env vars (`IMAGEKIT_PUBLIC_KEY`, `IMAGEKIT_PRIVATE_KEY`,
  `IMAGEKIT_URL_ENDPOINT`) or `config/imagekit.php`.
