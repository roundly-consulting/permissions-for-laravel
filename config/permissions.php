<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

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
    | uuid, ulid (unset reads as bigint). Any other value throws
    | InvalidConfigurationException rather than silently building bigint keys.
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
    | A boolean (true/false, 1/0, on/off, yes/no); anything else throws.
    |
    */

    'register_gate_check' => true,

    /*
    |--------------------------------------------------------------------------
    | Description fallback
    |--------------------------------------------------------------------------
    |
    | Role and permission `description` is a translatable attribute: it is stored
    | as a per-locale map and resolves to a string for the current locale. This
    | key decides how far the read falls back when the current locale has no value.
    |
    |   Fallback (default) — current locale, then the app's `fallback_locale`,
    |                        then null. The least-surprising, non-disclosing choice:
    |                        a description you have not translated for a locale never
    |                        surfaces content from an *unrelated* language.
    |   None               — current locale only; null when it is missing.
    |   Any                — current locale, then `fallback_locale`, then the FIRST
    |                        available locale. Descriptions never render blank, but a
    |                        value you left untranslated for one locale can surface in
    |                        another — a cross-locale disclosure. Opt in deliberately.
    |
    | Descriptions are developer/admin-facing labels, not user PII, so `Any` is a
    | reasonable opt-in; it is not the default because silently reaching into an
    | unrelated locale is a surprise. Override per model with a
    | `protected ?FallbackMode $translatableFallbackMode` property.
    |
    | A FallbackMode case or its string value (`fallback`, `none`, `any`); any
    | other value throws InvalidConfigurationException rather than silently
    | reading as Fallback.
    |
    */

    'description_fallback' => env('PERMISSIONS_DESCRIPTION_FALLBACK', FallbackMode::Fallback),

    /*
    |--------------------------------------------------------------------------
    | Permission catalog cache
    |--------------------------------------------------------------------------
    |
    | The registrar caches the permission catalog (id + name) to keep the Gate
    | check cheap. It auto-invalidates on every grant mutation and on role or
    | permission save/delete — after the surrounding transaction commits, so a
    | concurrent request never re-caches the pre-commit catalog. `store` of
    | "default" uses the app's default store.
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
