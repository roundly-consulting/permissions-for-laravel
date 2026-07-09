<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Shared grant/revoke/sync/has logic operating on whatever `permissions()`
 * relation the host exposes (BelongsToMany on Role, MorphToMany on the user).
 * Every using class (Role, and HasRoles consumers) defines `permissions()`.
 *
 * @phpstan-require-extends Model
 */
trait HasPermissions
{
    /**
     * Additively attach permissions — never detaches grants another caller made.
     *
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     */
    public function givePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): static
    {
        return $this->applyPermissionGrant($permissions, GrantMode::Additive);
    }

    /**
     * Make the given permissions the exact, complete set (detaches the rest).
     *
     * @param  iterable<mixed>  $permissions
     */
    public function syncPermissions(iterable $permissions): static
    {
        return $this->applyPermissionGrant([$permissions], GrantMode::Authoritative);
    }

    /**
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     */
    public function revokePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): static
    {
        $this->permissions()->detach($this->resolvePermissions($permissions)->modelKeys());
        $this->unsetRelation('permissions');
        $this->forgetCachedPermissions();

        return $this;
    }

    public function hasPermissionTo(string|BackedEnum|Permission $permission): bool
    {
        $name = $permission instanceof Permission
            ? $permission->name
            : PermissionRegistrar::nameOf($permission);

        return $this->relatedPermissions()->contains(
            static fn (Permission $candidate): bool => $candidate->name === $name,
        );
    }

    /**
     * @return Collection<int, string>
     */
    public function getPermissionNames(): Collection
    {
        return $this->relatedPermissions()
            ->map(static fn (Permission $permission): string => $permission->name)
            ->values();
    }

    /**
     * @param  iterable<mixed>  $permissions
     */
    protected function applyPermissionGrant(iterable $permissions, GrantMode $mode): static
    {
        $ids = $this->resolvePermissions($permissions)->modelKeys();

        if ($mode->detaches()) {
            $this->permissions()->sync($ids);
        } else {
            $this->permissions()->syncWithoutDetaching($ids);
        }

        $this->unsetRelation('permissions');
        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * The loaded direct-permission relation (eager-load aware, lazy otherwise).
     *
     * @return EloquentCollection<int, Permission>
     */
    protected function relatedPermissions(): EloquentCollection
    {
        /** @var EloquentCollection<int, Permission> $permissions */
        $permissions = $this->getRelationValue('permissions');

        return $permissions;
    }

    /**
     * Resolve names / enums / models / nested iterables to a de-duped collection.
     * Unknown names throw PermissionDoesNotExist.
     *
     * @param  iterable<mixed>  $permissions
     * @return EloquentCollection<int, Permission>
     */
    protected function resolvePermissions(iterable $permissions): EloquentCollection
    {
        /** @var EloquentCollection<int, Permission> $models */
        $models = new EloquentCollection;
        $names = [];

        foreach ($this->flattenGrantArguments($permissions) as $permission) {
            if ($permission instanceof Permission) {
                $models->push($permission);
            } elseif ($permission instanceof BackedEnum) {
                $names[] = (string) $permission->value;
            } elseif (is_string($permission)) {
                $names[] = $permission;
            } else {
                throw new PermissionException('Permissions must be strings, backed enums, or Permission models.');
            }
        }

        $names = array_values(array_unique($names));

        if ($names !== []) {
            $model = PermissionRegistrar::permissionModel();
            $found = $model::query()->whereIn('name', $names)->get();

            if ($found->count() < count($names)) {
                $missing = array_values(array_diff($names, $found->pluck('name')->all()));
                throw PermissionDoesNotExist::named((string) $missing[0]);
            }

            $models = $models->merge($found);
        }

        return $models->unique(static fn (Permission $permission): mixed => $permission->getKey())->values();
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<mixed>
     */
    protected function flattenGrantArguments(iterable $values): array
    {
        $flat = [];

        foreach ($values as $value) {
            if (is_iterable($value)) {
                foreach ($this->flattenGrantArguments($value) as $nested) {
                    $flat[] = $nested;
                }
            } else {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    protected function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
