# Changelog

All notable changes to `permissions-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Native, single-guard roles and permissions for any authenticatable model through the
  `HasRoles` trait — `assignRole()`, `syncRoles()`, `hasRole()`, `hasPermissionTo()`,
  `getAllPermissions()` (direct ∪ via roles).
- `Role` and `Permission` models with idempotent `findOrCreate()`, swappable via
  `permissions.models.*`.
- Additive `givePermissionTo()` alongside authoritative `syncPermissions()`, so independent
  services can register their own grants safely.
- Every name-taking method accepts a string or your own backed enum.
- Laravel Gate integration, so the `can:` middleware and `$user->can()` authorize against stored
  permissions — without ever overriding model policies.
- A `role()` query scope and bigint, UUID or ULID holder keys (`key_type`).
- A cached permission catalog that invalidates itself on every change and resets per Octane
  request and queued job; `permissions:cache-reset` for bulk writes.
- Translatable role and permission `description`s with a configurable locale fallback.
- `forgetAllAuthorization()` and the `permissions:prune-orphans` command to clean up grants of
  deleted holders.
