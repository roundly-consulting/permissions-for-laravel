<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Facades;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\HolderGrants;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\PermissionCache;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Testing\PermissionsFake;

/**
 * @method static HolderGrants<Model> for(Model $holder)
 * @method static Role role(string|BackedEnum $name)
 * @method static Role|null findRole(string|BackedEnum $name)
 * @method static Collection<int, Role> roles()
 * @method static Permission permission(string|BackedEnum $name)
 * @method static Permission|null findPermission(string|BackedEnum $name)
 * @method static Collection<int, Permission> permissions()
 * @method static bool exists(string|BackedEnum $name)
 * @method static SyncResult syncFrom(string $enum)
 * @method static SyncResult syncRolesFrom(string $enum)
 * @method static int pruneOrphans()
 * @method static PermissionCache cache()
 * @method static class-string<Role> roleModel()
 * @method static class-string<Permission> permissionModel()
 *
 * @see PermissionsManager
 */
final class Permissions extends Facade
{
    /**
     * Swap the manager for a recording fake and return it. Every operation still runs against
     * the database (grants land, the Gate answers), while each write — through this facade, an
     * injected manager, a `for()` handle, the `HasRoles` / `HasPermissions` traits or a model's
     * `findOrCreate()` — is recorded for the `assert*()` methods.
     */
    public static function fake(): PermissionsFake
    {
        $fake = app(PermissionsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PermissionsManager::class;
    }
}
