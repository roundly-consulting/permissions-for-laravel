# Changelog

All notable changes to `permissions-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- **Scoped and time-boxed roles and permissions**, opt-in behind the new
  `permissions.assignments.enabled` flag (default off):
  `Permissions::for($user)->in($project)->until($friday)->assignRole('editor')`. A scope is any
  Eloquent model; a window has an inclusive start and an exclusive end, in UTC. A scoped grant
  never counts outside its scope; unscoped grants count inside every scope unless
  `permissions.assignments.global_applies_in_scope` is false. Checks take several scopes (any of
  them), writes exactly one. The handle gains `in()`, `from()`, `until()`, `between()`, `at()`,
  and the reads `hasRole()`, `hasAnyRole()`, `hasPermissionTo()`, `roleNames()`,
  `permissionNames()` and `assignments()` (a list of the new `Assignment` DTO).
- **Owned roles**: `Permissions::owner($tenant)->role('reviewer')` (+ `findRole()`, `roles()`,
  `syncRolesFrom()`), so each tenant can define its own roles. An owned role is assignable only
  inside its owner's scope; `Permissions::resolveRoleOwnerUsing()` widens that (e.g. a project
  uses its tenant's roles).
- Scoped Gate checks with `Permissions::scope($project)`:
  `$user->can('posts.edit', Permissions::scope($project))`, `@can`, `Gate::authorize()`. The
  hook still answers only `true` or `null`. `Permissions::resolveScopeUsing()` gives scope-less
  Gate checks an ambient scope.
- `Http\Middleware\EnsurePermission` route middleware:
  `EnsurePermission::using('posts.edit', scope: 'project')`.
- `HasRoles` sugar: `hasRoleIn()`, `hasPermissionIn()` and the `roleIn()` query scope.
- `Permissions::forgetScope()`, `Permissions::pruneExpired()` and the
  `permissions:prune-expired --after="30 days"` command; `pruneOrphans()` also prunes
  assignments of deleted holders and scopes and roles of deleted owners while the flag is on.
- The fake records scope and window; its holder assertions take optional `scope:` / `from:` /
  `until:` filters, `assertRoleRegistered()` an `owner:` filter, and it gains
  `assertScopeForgotten()`, `assertNoScopeForgotten()`, `assertExpiredPruned()` and
  `assertNoExpiredPruned()`.
- Config: `assignments.enabled`, `assignments.scope_key_type`,
  `assignments.global_applies_in_scope`, `table_names.role_assignments`,
  `table_names.permission_assignments`. `php artisan about` reports the assignments state.
- Three publishable migrations: `add_owner_to_roles_table` (role owner columns; the `roles`
  name unique index becomes `(owner_key, name)`), `create_role_assignments_table`,
  `create_permission_assignments_table`.

### Changed

- With `permissions.assignments.enabled` on (never while off): `role()`, `findRole()`,
  `roles()`, `syncRolesFrom()`, `Role::findOrCreate()`, role-name resolution and the `role()`
  query scope work on global roles only; unscoped `hasRole()` / `hasPermissionTo()` and the Gate
  also count unscoped time-boxed grants while their window is open; unscoped `syncRoles()`,
  `syncPermissions()`, `removeRole()` and `revokePermissionTo()` also clear the holder's
  unscoped assignment rows; `forgetAllAuthorization()` also clears every assignment of the
  holder in every scope. The `roles` / `permissions` relations, `getRoleNames()` and
  `getAllPermissions()` keep listing 2.0 grants only — use
  `Permissions::for($user)->assignments()` for everything.
- `Role` hides the new `owner_type` and `owner_key` attributes when serialized.
- Maintenance: requires `illuminate/http` (for the middleware; every Laravel app has it).

### Upgrade

Nothing changes until you opt in — with the flag off the package sends exactly the 2.0
queries. To use the new features:

1. If your scope models use another key type than your holders, set
   `permissions.assignments.scope_key_type` first.
2. `php artisan vendor:publish --tag=permissions-migrations` and `php artisan migrate`. If you
   renamed the `roles_name_unique` index by hand, adjust `add_owner_to_roles_table`.
3. Set `PERMISSIONS_ASSIGNMENTS=true`.
4. Optional: call `Permissions::forgetScope($model)` from your scope models' `deleting` hooks and
   schedule `permissions:prune-expired`.

### Planned for the next major

- Fold the 2.0 `model_roles` / `model_permissions` tables into the assignment tables and drop
  the `permissions.assignments.enabled` flag (scoped and time-boxed grants always on).

## 2.0.0 - 2026-11-07

### Changed

- **Breaking:** requires Laravel 13; Laravel 12 is no longer supported. Upgrade the application to Laravel 13 before updating.
- Requires the 2.x releases of the Roundly packages it builds on (their Laravel 13 releases).
- Maintenance: tested on PHP 8.4, 8.5 and 8.6; the test suite runs on Pest 5.

## 1.0.2 - 2026-10-17

### Fixed

- Factories build the configured model when a host swaps it.

## 1.0.1 - 2026-10-11

### Changed

- Maintenance: requires the latest roundly packages — package-toolkit `^1.3.0`, enums `^1.1.0`,
  translatable `^1.1.0`; dev: testing `^1.2.1`.
- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.

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
