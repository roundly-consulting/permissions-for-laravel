<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use BackedEnum;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * Register one catalog row per case of a backed enum — the bootstrap for the enums the package
 * already accepts everywhere else.
 *
 * **Additive, like every grant in this package:** it creates the rows the enum names and never
 * deletes or renames a row the enum does not name, because the catalog is shared — another
 * service may have registered its own permissions in the same table. Rows are created as the
 * given (configured) model class, so the model events that invalidate the catalog cache fire,
 * and `createOrFirst` keeps concurrent boots race-safe.
 */
final readonly class SyncFromEnum
{
    /**
     * @param  string  $enum  a backed enum class; each case's `value` is a name
     * @param  class-string<Role>|class-string<Permission>  $model  the model to create rows as
     */
    public function execute(string $enum, string $model): SyncResult
    {
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw PermissionException::notABackedEnum($enum);
        }

        $names = array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        );

        $registered = $model::query()->whereIn('name', $names)->pluck('name')->all();
        $created = [];

        foreach (array_diff($names, $registered) as $name) {
            if ($model::query()->createOrFirst(['name' => $name])->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        return new SyncResult(
            created: $created,
            existing: array_values(array_diff($names, $created)),
        );
    }
}
