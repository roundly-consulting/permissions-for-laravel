<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * Direct-permission grants and checks on whatever `permissions()` relation the host exposes
 * (BelongsToMany on Role, MorphToMany on the user). Every using class (Role, and HasRoles
 * consumers) defines `permissions()`.
 *
 * Writes delegate to the {@see PermissionsManager} (`Permissions::for($this)->…`), so a host's
 * container override applies and `Permissions::fake()` records them; reads stay on the model.
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
        app(PermissionsManager::class)->for($this)->givePermissionTo(...$permissions);

        return $this;
    }

    /**
     * Make the given permissions the exact, complete set (detaches the rest).
     *
     * @param  iterable<mixed>  $permissions
     */
    public function syncPermissions(iterable $permissions): static
    {
        app(PermissionsManager::class)->for($this)->syncPermissions($permissions);

        return $this;
    }

    /**
     * @param  string|BackedEnum|Permission|iterable<mixed>  ...$permissions
     */
    public function revokePermissionTo(string|BackedEnum|Permission|iterable ...$permissions): static
    {
        app(PermissionsManager::class)->for($this)->revokePermissionTo(...$permissions);

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
}
