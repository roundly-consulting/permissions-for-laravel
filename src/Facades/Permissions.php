<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Facades;

use BackedEnum;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * @method static \Illuminate\Database\Eloquent\Collection<int, \RoundlyConsulting\Permissions\Models\Permission> getPermissions()
 * @method static bool permissionExists(string|BackedEnum $name)
 * @method static void forgetCachedPermissions()
 * @method static void flushMemo()
 *
 * @see PermissionRegistrar
 */
final class Permissions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PermissionRegistrar::class;
    }
}
