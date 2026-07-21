# Release Notes for ImageKit Toolkit

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
