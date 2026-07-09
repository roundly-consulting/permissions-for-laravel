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
    | Load migrations
    |--------------------------------------------------------------------------
    |
    | Load the package migrations automatically. Set to false if you publish
    | the migrations and want to own the files in your application instead.
    |
    */

    'load_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Permission catalog cache
    |--------------------------------------------------------------------------
    |
    | The registrar caches the permission catalog (id + name) to keep the Gate
    | check cheap. It auto-invalidates on every grant mutation and on role or
    | permission save/delete. `store` of "default" uses the app's default store.
    |
    */

    'cache' => [
        'store' => env('PERMISSIONS_CACHE_STORE', 'default'),
        'key' => 'permissions.cache',
        'ttl' => 60 * 60 * 24, // seconds
    ],

];
