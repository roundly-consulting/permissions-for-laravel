<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap either model for a subclass; the package resolves both through this
    | config everywhere, never by a hard-coded FQCN.
    |
    */

    'models' => [
        'role' => Role::class,
        'permission' => Permission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Table names are configurable for hygiene; column names are fixed (there
    | is no column-name indirection). Change these before the first migrate.
    |
    */

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'permission_role' => 'permission_role',
        'model_roles' => 'model_roles',
        'model_permissions' => 'model_permissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model key type
    |--------------------------------------------------------------------------
    |
    | The primary-key type of the models that hold roles and permissions. This
    | drives the `model_id` column on the junction tables. Use "uuid" or "ulid"
    | when your holders use HasUuids / HasUlids. Set this before you publish and
    | run the migrations — the schema freezes once released. Supported: bigint,
    | uuid, ulid. An unrecognized value falls back to bigint.
    |
    */

    'key_type' => env('PERMISSIONS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Gate check
    |--------------------------------------------------------------------------
    |
    | Register the `Gate::before` permission check so `can:<permission>` route
    | middleware and `$user->can('<permission>')` resolve against permissions.
    |
    */

    'register_gate_check' => true,

    /*
    |--------------------------------------------------------------------------
    | Permission catalog cache
    |--------------------------------------------------------------------------
    |
    | The registrar caches the permission catalog (id + name) to keep the Gate
    | check cheap. It auto-invalidates on every grant mutation and on role or
    | permission save/delete. `store` of "default" uses the app's default store.
    |
    | The short default TTL is defense-in-depth: bulk writes that bypass model
    | events (seeders using DB::table/insert/upsert/delete/truncate) leave the
    | catalog stale only until it expires. Run `permissions:cache-reset` after
    | such writes to invalidate immediately.
    |
    */

    'cache' => [
        'store' => env('PERMISSIONS_CACHE_STORE', 'default'),
        'key' => 'permissions.cache',
        'ttl' => (int) env('PERMISSIONS_CACHE_TTL', 300), // seconds
    ],

];
