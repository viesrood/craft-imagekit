# Release Notes for ImageKit Toolkit

## 1.0.0 - 2026-07-21

### Added

- Eerste release.
- Native Craft-image-transformer: registreert ImageKit als transformer via
  `craft\services\ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS`, zodat Crafts
  ingebouwde transform-API (`asset.url({ width: 400 })`) ImageKit-URL's kan opleveren.
- Twig-helpers `imagekit()` (functie + filter) en `imagekit_srcset()` voor realtime
  transformatie-URL's op een Media Library-pad of een bestaande (externe) URL (web-proxy).
- Optioneel ondertekende (signed) URL's met instelbare geldigheidsduur.
- CP-hulpprogramma om een lokaal bestand of URL naar de ImageKit Media Library te uploaden
  en de omgezette URL + het Media Library-pad terug te krijgen.
- Instellingen via env-vars (`IMAGEKIT_PUBLIC_KEY`, `IMAGEKIT_PRIVATE_KEY`,
  `IMAGEKIT_URL_ENDPOINT`) of `config/imagekit.php`.
