# Changelog

All notable changes to `permissions-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- Maintenance: requires the latest roundly packages — package-toolkit `^1.3.0`, enums `^1.1.0`,
  translatable `^1.1.0`; dev: testing `^1.2.1`.

## 1.0.0 - 2026-10-03

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
- A cached permission catalog that invalidates itself on every change once it commits (never
  mid-transaction, so concurrent requests can't re-cache a stale catalog) and resets per Octane
  request and queued job; `permissions:cache-reset` for bulk writes.
- Translatable role and permission `description`s with a configurable locale fallback.
- `forgetAllAuthorization()` and the `permissions:prune-orphans` command to clean up grants of
  deleted holders.
- A complete `Permissions` facade over an injectable `PermissionsManager`, backed by one action
  per write (`FindOrCreateRole`, `FindOrCreatePermission`, `GrantRoles`, `RemoveRoles`,
  `GrantPermissions`, `RevokePermissions`, `ForgetAuthorization`, `SyncFromEnum`,
  `PruneOrphans`) — facade, dependency injection and the raw action run the same code:
  `role()`, `permission()`, `findRole()`, `findPermission()`, `roles()`, `permissions()`,
  `exists()`, `roleModel()`, `permissionModel()`, `pruneOrphans()`.
- `Permissions::syncFrom(Enum::class)` / `syncRolesFrom(Enum::class)`: register a row per case of
  a backed enum, additively, returning a `SyncResult` (`created`, `existing`, `changed()`).
- `Permissions::for($holder)`: the trait write verbs (`assignRole`, `removeRole`, `syncRoles`,
  `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `forgetAllAuthorization`) scoped
  to one holder. The traits and `Role::findOrCreate()` / `Permission::findOrCreate()` delegate to
  the manager.
- `Permissions::cache()->forget()` / `->flushMemo()`.
- `Permissions::fake()`: a recording `PermissionsFake` that sees every write — facade, injected
  manager, `for()` handle, traits, model `findOrCreate()` and the artisan commands — with an
  `assert*` / negative pair for role and permission registration, enum syncs, role
  assign/remove/sync, permission grant/revoke/sync, `forgetAllAuthorization()`, orphan pruning,
  cache flushes and memo flushes.

### Changed

- The facade root is now `PermissionsManager`; `PermissionRegistrar` is the catalog cache it
  owns. `Permissions::getPermissions()` → `permissions()`, `permissionExists()` → `exists()`,
  `forgetCachedPermissions()` / `flushMemo()` → `cache()->forget()` / `cache()->flushMemo()`.
  The registrar's instance methods follow the same names and are `@internal`; its static config
  resolvers stay public. `RoleModel`, `PermissionModel`, `DescriptionFallback` and
  `PermissionRegistrar::nameOf()` are `@internal`.
- The traits' protected helpers (`applyPermissionGrant()`, `resolvePermissions()`,
  `resolveRoles()`, `normalizeRoleNames()`, `flattenGrantArguments()`,
  `forgetCachedPermissions()`) are gone — the logic lives in the actions.
- A grant scoped to a holder with no key now throws a `PermissionException` before touching the
  pivot, instead of a raw database `QueryException`; role writes on a model without `HasRoles`
  (including `Permissions::for($role)`) and permission writes on a model without
  `HasPermissions` are refused the same way.
- `register_gate_check` and `description_fallback` are read strictly: a typo throws
  `InvalidConfigurationException` naming the key instead of quietly keeping the gate hook on or
  reading as `Fallback`, and the `about` row now reports the gate check exactly as boot reads it.
