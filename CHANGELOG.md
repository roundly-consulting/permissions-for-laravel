# Changelog

All notable changes to `permissions-for-laravel` will be documented in this file.

## Unreleased

### Fixed

- A per-model `$translatableFallbackMode` on a `Role`/`Permission` subclass is no longer
  overwritten by `permissions.description_fallback`; the config only fills models that declare
  none.
