<p align="center">
  <a href="https://roundly-consulting.com/open-source">
    <img src="art/hero.png" alt="Permissions For Laravel — Roundly open source" width="100%">
  </a>
</p>

# Permissions for Laravel

Roles & permissions for Laravel — native, single-guard, cache-backed. Give any
authenticatable model roles and direct permissions, resolve effective permissions
(direct ∪ via-roles), and make Laravel's `can:` gate and middleware authorize against
them. Zero third-party runtime dependencies — a native roles & permissions
system, scoped to what an API service actually needs.

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`

Models that hold roles/permissions default to auto-incrementing `bigint` keys. UUID and
ULID holders are supported via the `key_type` config key — set it **before** you publish and
run the migrations (see [Configuration](#configuration)).

Installs [`roundly-consulting/enums-for-laravel`](https://github.com/roundly-consulting/enums-for-laravel)
for enum conventions and
[`roundly-consulting/package-toolkit-for-laravel`](https://github.com/roundly-consulting/package-toolkit-for-laravel)
for the shared package bootstrapper.

## Installation

```bash
composer require roundly-consulting/permissions-for-laravel
```

Publish the config file — the holder key type is baked into the schema, so set it first:

```bash
php artisan vendor:publish --tag="permissions-config"
```

Then publish and run the migrations:

```bash
php artisan vendor:publish --tag="permissions-migrations"
php artisan migrate
```

**Migrations are publish-only.** The package does not load them, so `php artisan migrate`
creates nothing until you have published them into your app's `database/migrations`. They
land timestamped and in dependency order; publishing again overwrites in place rather than
adding a second copy.

## Configuration

The published `config/permissions.php`:

```php
return [
    'models' => [
        'role' => RoundlyConsulting\Permissions\Models\Role::class,
        'permission' => RoundlyConsulting\Permissions\Models\Permission::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'permission_role' => 'permission_role',
        'model_roles' => 'model_roles',
        'model_permissions' => 'model_permissions',
    ],

    'key_type' => env('PERMISSIONS_KEY_TYPE', 'bigint'),

    'register_gate_check' => true,

    'cache' => [
        'store' => env('PERMISSIONS_CACHE_STORE', 'default'),
        'key' => 'permissions.cache',
        'ttl' => (int) env('PERMISSIONS_CACHE_TTL', 300), // seconds
    ],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `models.role` | `class-string` | `Role::class` | Role model; swap for a subclass to extend it. |
| `models.permission` | `class-string` | `Permission::class` | Permission model; swap for a subclass. |
| `table_names.*` | `string` | see above | Table names (column names are fixed). Set before you migrate. |
| `key_type` | `string` | `bigint` (`PERMISSIONS_KEY_TYPE`) | Key type of the holder models — `bigint`, `uuid`, or `ulid`. Drives the `model_id` column on the junction tables. **Set before you migrate** (the schema freezes at release). An unrecognized value falls back to `bigint`. |
| `register_gate_check` | `bool` | `true` | Register the `Gate::before` hook so `can:<permission>` resolves. |
| `cache.store` | `string` | `default` (`PERMISSIONS_CACHE_STORE`) | Cache store for the permission catalog; `default` uses the app store. |
| `cache.key` | `string` | `permissions.cache` | Cache key for the catalog. |
| `cache.ttl` | `int` | `300` (`PERMISSIONS_CACHE_TTL`) | Catalog cache TTL in seconds (short by default as defense-in-depth against bulk writes — see [Cache](#cache)). |

There is **no guard concept**: no `guard_name` column, config, or parameter. The package
is single-guard by design.

### Holder key type

`key_type` describes the models that *hold* roles and permissions, never this package's own
tables (`roles` and `permissions` always own an auto-incrementing key). It types the
`model_id` column on the two junction tables:

| `key_type` | `model_id` column | Use when your holders… |
|---|---|---|
| `bigint` (default) | `unsignedBigInteger` | use Laravel's default auto-incrementing keys |
| `uuid` | `uuid` | use `HasUuids` |
| `ulid` | `ulid` | use `HasUlids` |

```dotenv
PERMISSIONS_KEY_TYPE=uuid
```

## Usage

### Add the trait

Add `HasRoles` to any authenticatable model. There is no `$guard_name` to set.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use RoundlyConsulting\Permissions\Concerns\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

### Create roles and permissions

`findOrCreate` is idempotent under the unique `name` index, and it creates and returns the
model you configured at `permissions.models.*` — so a host subclass gets its own class back,
its own model events, and its own observers:

```php
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

$role = Role::findOrCreate('administrator');
$permission = Permission::findOrCreate('auth.users.view');
```

Both models carry an optional translatable `description` (a JSON locale map). Read it with the
`description()` accessor, which falls back to the current app locale:

```php
$permission->update(['description' => ['en' => 'View users', 'sk' => 'Zobraziť používateľov']]);

$permission->description();      // current app locale, or null
$permission->description('sk');  // "Zobraziť používateľov"
```

### Grant permissions to a role

`givePermissionTo` is **additive** — it never strips grants another caller registered — so
independent services can each register their own permissions safely. `syncPermissions` is
authoritative (the given set becomes the complete set):

```php
$role->givePermissionTo('auth.users.view', 'auth.users.edit'); // additive
$role->syncPermissions(['auth.users.view']);                   // exact set
$role->revokePermissionTo('auth.users.view');
```

### Assign roles and read permissions

```php
$user->assignRole('administrator');
$user->removeRole('administrator');
$user->syncRoles(['editor']);

$user->hasRole('administrator');                 // bool
$user->hasRole(['administrator', 'editor']);     // any of

$user->getRoleNames();          // Collection<string>
$user->getDirectPermissions();  // direct grants only
$user->getAllPermissions();     // direct ∪ via-roles, de-duped — the JWT claim source
$user->getAllPermissions()->pluck('name');
```

### Accept your own enums

Every name-taking method accepts a `string` or a `BackedEnum`, so you can pass your own
permission/role enums (persisted as `->value`):

```php
use RoundlyConsulting\Enums\Helpers;

enum Permission: string
{
    use Helpers;

    case ViewUsers = 'auth.users.view';
}

$role->givePermissionTo(Permission::ViewUsers);
$user->hasPermissionTo(Permission::ViewUsers);
```

### Gate & middleware

With `register_gate_check` on, permissions resolve through Laravel's Gate, so the native
`can:` middleware works out of the box:

```php
Route::get('/users', UsersController::class)->middleware('can:auth.users.view');

$user->can('auth.users.view');           // true when granted directly or via a role
Gate::forUser($user)->allows('auth.users.view');
```

The hook returns `null` for any ability that is not a registered permission, so your
custom gates and policies still run untouched. Set `register_gate_check` to `false` to
disable it entirely.

**Permission checks never override model policies.** The hook only answers *argument-less*
ability checks that name one of your permissions. As soon as a check carries a model or any
argument — `$user->can('update', $post)`, or the `can:update,post` middleware — the hook
returns `null` and defers to your gate/policy. So a permission named `update` will authorize
`$user->can('update')`, but `PostPolicy::update()` (ownership, tenancy, …) still decides
`$user->can('update', $post)`. Coarse permissions and model-scoped policies coexist without
one silently swallowing the other.

### Query scope

```php
User::query()->role('administrator')->count();
User::query()->role(['administrator', 'editor'])->get();
```

### Cache

The permission catalog is cached and auto-invalidates on every grant mutation and on any
role/permission save or delete. You rarely need to flush it manually, but you can:

```php
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Facades\Permissions;

app(PermissionRegistrar::class)->forgetCachedPermissions();
Permissions::forgetCachedPermissions();
Permissions::permissionExists('auth.users.view');
```

Or from the CLI:

```bash
php artisan permissions:cache-reset
```

**Bulk writes bypass invalidation.** Auto-invalidation is driven by Eloquent model events,
which mass operations do **not** fire: `Permission::query()->delete()`, `::insert()`,
`::upsert()`, `DB::table('permissions')->...`, and `truncate()`. After seeding or importing
permissions that way, invalidate the catalog explicitly:

```bash
php artisan permissions:cache-reset
```

```php
Permissions::forgetCachedPermissions(); // or after your seeder runs
```

The short default `cache.ttl` (300s) bounds how long a missed invalidation can linger; use a
longer TTL only if all writes go through Eloquent.

**Octane & queue workers.** The registrar keeps a small per-request memo on top of the shared
cache store. The package resets that memo at each Octane request/task/tick and each queued
job, so a long-lived worker never serves a memo that outlived its authority. For invalidation
to propagate *across* workers, use a shared cache store (`redis`, `database`, `memcached`) —
the per-worker `array` store cannot see another worker's `forgetCachedPermissions()`.

### Cleaning up when a holder is deleted

The `model_roles` / `model_permissions` tables are polymorphic pivots, so they can't foreign-key
the holder table — deleting a user leaves its grant rows behind. If your app ever reuses primary
keys (truncate + reseed, imports, non-autoincrement strategies), a new record inheriting an old
id would silently inherit the deleted holder's roles and permissions. Prevent that in one of two
ways.

Detach grants when the holder is deleted, e.g. from a model `deleting` hook:

```php
protected static function booted(): void
{
    static::deleting(fn (self $model) => $model->forgetAllAuthorization());
}
```

Or sweep orphaned rows periodically (e.g. from the scheduler) with the prune command, which
deletes pivot rows whose `model_type` + `model_id` no longer resolve to a model:

```bash
php artisan permissions:prune-orphans
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

Please open an issue or pull request on the repository.

## License

The MIT License (MIT). Copyright (c) Roundly Consulting. See [LICENSE.md](LICENSE.md).
