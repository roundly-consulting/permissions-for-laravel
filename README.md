<p align="center">
  <a href="https://roundly-consulting.com/open-source">
    <img src="art/hero.png" alt="Permissions For Laravel — Roundly open source" width="100%">
  </a>
</p>

# Permissions for Laravel

Roles & permissions for Laravel — native, single-guard, cache-backed. Give any
authenticatable model roles and direct permissions, resolve effective permissions
(direct ∪ via-roles), and make Laravel's `can:` gate and middleware authorize against
them. Zero third-party runtime dependencies — a native replacement for
`acme/laravel-permission`, scoped to what an API service actually needs.

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`

Installs [`roundly-consulting/enums-for-laravel`](https://github.com/roundly-consulting/enums-for-laravel)
for enum conventions.

## Installation

```bash
composer require roundly-consulting/permissions-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="permissions-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="permissions-config"
```

The package auto-loads its migrations, so publishing them is only needed if you want to
own the files. Set `permissions.load_migrations` to `false` when you do.

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

    'register_gate_check' => true,

    'load_migrations' => true,

    'cache' => [
        'store' => env('PERMISSIONS_CACHE_STORE', 'default'),
        'key' => 'permissions.cache',
        'ttl' => 60 * 60 * 24, // seconds
    ],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `models.role` | `class-string` | `Role::class` | Role model; swap for a subclass to extend it. |
| `models.permission` | `class-string` | `Permission::class` | Permission model; swap for a subclass. |
| `table_names.*` | `string` | see above | Table names (column names are fixed). Set before the first migrate. |
| `register_gate_check` | `bool` | `true` | Register the `Gate::before` hook so `can:<permission>` resolves. |
| `load_migrations` | `bool` | `true` | Auto-load the package migrations. |
| `cache.store` | `string` | `default` (`PERMISSIONS_CACHE_STORE`) | Cache store for the permission catalog; `default` uses the app store. |
| `cache.key` | `string` | `permissions.cache` | Cache key for the catalog. |
| `cache.ttl` | `int` | `86400` | Catalog cache TTL in seconds. |

There is **no guard concept**: no `guard_name` column, config, or parameter. The package
is single-guard by design.

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

`findOrCreate` is idempotent under the unique `name` index and returns the configured
concrete model:

```php
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

$role = Role::findOrCreate('administrator');
$permission = Permission::findOrCreate('auth.users.view');
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
