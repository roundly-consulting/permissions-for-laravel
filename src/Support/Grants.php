<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * Grant-argument resolution and holder validation shared by the grant actions and the
 * read side of the traits.
 *
 * Every name-taking grant accepts strings, backed enums, saved models and nested
 * iterables of those; this is the one place that flattens, validates and resolves them.
 *
 * @internal
 */
final class Grants
{
    /**
     * Flatten nested iterables into one list, preserving order.
     *
     * @param  iterable<mixed>  $values
     * @return list<mixed>
     */
    public static function flatten(iterable $values): array
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_iterable($value)) {
                foreach (self::flatten($value) as $nested) {
                    $flat[] = $nested;
                }
            } else {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    /**
     * Normalize names / enums / models / nested iterables to a de-duplicated name list,
     * without touching the database.
     *
     * @param  class-string<Role>|class-string<Permission>  $model  the model instances accepted
     * @param  string  $what  the singular noun for the error message ("Role" or "Permission")
     * @return list<string>
     */
    public static function names(mixed $values, string $model, string $what): array
    {
        $names = [];

        foreach (self::flatten(is_iterable($values) ? $values : [$values]) as $value) {
            $names[] = match (true) {
                $value instanceof $model => $value->name,
                $value instanceof BackedEnum => (string) $value->value,
                is_string($value) => $value,
                default => throw PermissionException::invalidType($what),
            };
        }

        return array_values(array_unique($names));
    }

    /**
     * Resolve role arguments to saved models. Unknown names throw RoleDoesNotExist.
     *
     * @param  iterable<mixed>  $roles
     * @return EloquentCollection<int, Role>
     */
    public static function roles(iterable $roles): EloquentCollection
    {
        /** @var EloquentCollection<int, Role> $models */
        $models = new EloquentCollection;
        $names = [];

        foreach (self::flatten($roles) as $role) {
            if ($role instanceof Role) {
                self::assertSaved($role, 'Role');
                $models->push($role);
            } else {
                $names[] = self::nameOrFail($role, 'Role');
            }
        }

        $names = array_values(array_unique($names));

        if ($names !== []) {
            $found = RoleModel::query()->whereIn('name', $names)->get();

            if ($found->count() < count($names)) {
                throw RoleDoesNotExist::named(self::firstMissing($names, $found->pluck('name')->all()));
            }

            $models = $models->merge($found);
        }

        return $models->unique(static fn (Role $role): mixed => $role->getKey())->values();
    }

    /**
     * Resolve permission arguments to saved models. Unknown names throw
     * PermissionDoesNotExist.
     *
     * @param  iterable<mixed>  $permissions
     * @return EloquentCollection<int, Permission>
     */
    public static function permissions(iterable $permissions): EloquentCollection
    {
        /** @var EloquentCollection<int, Permission> $models */
        $models = new EloquentCollection;
        $names = [];

        foreach (self::flatten($permissions) as $permission) {
            if ($permission instanceof Permission) {
                self::assertSaved($permission, 'Permission');
                $models->push($permission);
            } else {
                $names[] = self::nameOrFail($permission, 'Permission');
            }
        }

        $names = array_values(array_unique($names));

        if ($names !== []) {
            $found = PermissionModel::query()->whereIn('name', $names)->get();

            if ($found->count() < count($names)) {
                throw PermissionDoesNotExist::named(self::firstMissing($names, $found->pluck('name')->all()));
            }

            $models = $models->merge($found);
        }

        return $models->unique(static fn (Permission $permission): mixed => $permission->getKey())->values();
    }

    /**
     * The holder's role relation — refusing a model that cannot hold roles (no
     * `HasRoles`) or has no key yet (its null key would poison the pivot).
     *
     * @return MorphToMany<Role, Model>
     */
    public static function rolesOf(Model $holder): MorphToMany
    {
        if (! method_exists($holder, 'roles') || ! self::uses($holder, HasRoles::class)) {
            throw PermissionException::cannotHold($holder, 'roles', 'HasRoles');
        }

        self::assertHolderSaved($holder);

        /** @var MorphToMany<Role, Model> $relation */
        $relation = $holder->roles();

        return $relation;
    }

    /**
     * The holder's direct-permission relation — refusing a model that cannot hold
     * permissions (no `HasPermissions`/`HasRoles`) or has no key yet.
     *
     * @return BelongsToMany<Permission, Model>
     */
    public static function permissionsOf(Model $holder): BelongsToMany
    {
        if (! method_exists($holder, 'permissions') || ! self::uses($holder, HasPermissions::class)) {
            throw PermissionException::cannotHold($holder, 'permissions', 'HasPermissions');
        }

        self::assertHolderSaved($holder);

        /** @var BelongsToMany<Permission, Model> $relation */
        $relation = $holder->permissions();

        return $relation;
    }

    private static function uses(Model $holder, string $trait): bool
    {
        return in_array($trait, class_uses_recursive($holder), true);
    }

    /**
     * Only a null key is refused — that is what poisons the pivot. A holder already deleted
     * (`exists` false, key kept) still passes, so `forgetAllAuthorization()` works from a
     * `deleted` hook as well as a `deleting` one.
     */
    private static function assertHolderSaved(Model $holder): void
    {
        if ($holder->getKey() === null) {
            throw PermissionException::unsavedHolder($holder);
        }
    }

    private static function assertSaved(Model $model, string $what): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw PermissionException::unsavedModel($what);
        }
    }

    private static function nameOrFail(mixed $value, string $what): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            is_string($value) => $value,
            default => throw PermissionException::invalidType($what),
        };
    }

    /**
     * @param  list<string>  $wanted
     * @param  array<array-key, mixed>  $found
     */
    private static function firstMissing(array $wanted, array $found): string
    {
        return (string) array_values(array_diff($wanted, $found))[0];
    }
}
