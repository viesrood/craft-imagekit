# Release Notes for ImageKit Toolkit

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
