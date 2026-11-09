<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Support\ScopeReference;

/**
 * The roles one owner (a tenant, a team) defines for itself — `Permissions::owner($tenant)`.
 *
 *     Permissions::owner($tenant)->role('reviewer')->givePermissionTo('posts.review');
 *     Permissions::for($user)->in($tenant)->assignRole('reviewer'); // the tenant's own reviewer
 *
 * Two owners can each have a `reviewer`, and neither clashes with a global one. An owned role is
 * assignable only inside its owner's scope (widen it with `Permissions::resolveRoleOwnerUsing()`);
 * permissions stay global. Requires `permissions.assignments.enabled`.
 */
final readonly class OwnedRoles
{
    public function __construct(
        private PermissionsManager $manager,
        private Model $owner,
    ) {}

    /** Find or create this owner's role of that name, as the configured role class. */
    public function role(string|BackedEnum $name): Role
    {
        return $this->manager->ownedRole($this->owner, $name);
    }

    /** This owner's role of that name, or null. A live query. */
    public function findRole(string|BackedEnum $name): ?Role
    {
        return $this->query()->where('name', PermissionRegistrar::nameOf($name))->first();
    }

    /**
     * Every role this owner defines, ordered by name. A live query.
     *
     * @return EloquentCollection<int, Role>
     */
    public function roles(): EloquentCollection
    {
        return $this->query()->orderBy('name')->get();
    }

    /**
     * Register one owned role per case of a backed enum. Additive.
     *
     * @param  string  $enum  a backed enum class
     */
    public function syncRolesFrom(string $enum): SyncResult
    {
        return $this->manager->syncOwnedRolesFrom($this->owner, $enum);
    }

    /** @return Builder<Role> */
    private function query(): Builder
    {
        AssignmentRows::assertEnabled();

        return RoleModel::query()->where('owner_key', ScopeReference::of($this->owner)->ownerKey());
    }
}
