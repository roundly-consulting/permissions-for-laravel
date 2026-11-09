<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * Role-argument resolution for scoped writes, with owned roles.
 *
 * Inside a scope a name resolves to the scope's own role first, then to the role of the extra
 * owner `Permissions::resolveRoleOwnerUsing()` names for that scope, then to the global role.
 * An owned role passed as a model is accepted only inside one of those owners' scopes; without
 * a scope, only global roles resolve.
 *
 * @internal
 */
final class ScopedRoles
{
    /**
     * Resolve each argument to one role: the closest owner wins.
     *
     * @param  iterable<mixed>  $roles
     * @return EloquentCollection<int, Role>
     */
    public static function resolve(iterable $roles, ?Model $scope): EloquentCollection
    {
        return self::find($roles, $scope, everyMatch: false);
    }

    /**
     * Resolve each name to every role it can mean in this scope (owned and global) — what a
     * removal must clear.
     *
     * @param  iterable<mixed>  $roles
     * @return EloquentCollection<int, Role>
     */
    public static function resolveEveryMatch(iterable $roles, ?Model $scope): EloquentCollection
    {
        return self::find($roles, $scope, everyMatch: true);
    }

    /**
     * The owner keys whose roles count in this scope, closest first, global (`''`) last.
     *
     * @return list<string>
     */
    public static function ownerKeys(?Model $scope): array
    {
        if ($scope === null) {
            return [''];
        }

        $keys = [ScopeReference::of($scope)->ownerKey()];
        $extra = app(ScopeResolvers::class)->roleOwnerOf($scope);

        if ($extra !== null) {
            $keys[] = ScopeReference::of($extra)->ownerKey();
        }

        $keys[] = '';

        return array_values(array_unique($keys));
    }

    /**
     * @param  iterable<mixed>  $roles
     * @return EloquentCollection<int, Role>
     */
    private static function find(iterable $roles, ?Model $scope, bool $everyMatch): EloquentCollection
    {
        $ownerKeys = self::ownerKeys($scope);

        /** @var EloquentCollection<int, Role> $models */
        $models = new EloquentCollection;
        $names = [];

        foreach (Grants::flatten($roles) as $role) {
            if ($role instanceof Role) {
                if (! $role->exists || $role->getKey() === null) {
                    throw PermissionException::unsavedModel('Role');
                }

                if (! in_array((string) $role->getAttribute('owner_key'), $ownerKeys, true)) {
                    throw PermissionException::roleOutsideOwner($role->name);
                }

                $models->push($role);
            } else {
                $names[] = Grants::names($role, Role::class, 'Role')[0];
            }
        }

        $names = array_values(array_unique($names));

        if ($names !== []) {
            $found = RoleModel::query()
                ->whereIn('name', $names)
                ->whereIn('owner_key', $ownerKeys)
                ->get();

            foreach ($names as $name) {
                $candidates = $found->filter(static fn (Role $role): bool => $role->name === $name)
                    ->sortBy(static fn (Role $role): int => (int) array_search((string) $role->getAttribute('owner_key'), $ownerKeys, true))
                    ->values();

                if ($candidates->isEmpty()) {
                    throw RoleDoesNotExist::named($name);
                }

                foreach ($everyMatch ? $candidates : $candidates->take(1) as $candidate) {
                    $models->push($candidate);
                }
            }
        }

        return $models->unique(static fn (Role $role): mixed => $role->getKey())->values();
    }
}
