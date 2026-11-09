<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Actions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\DataTransferObjects\SyncResult;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\AssignmentRows;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopeReference;

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
     * @param  Model|null  $owner  roles only, assignments on: register the roles this model owns
     */
    public function execute(string $enum, string $model, ?Model $owner = null): SyncResult
    {
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw PermissionException::notABackedEnum($enum);
        }

        $names = array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        );

        $scoped = $this->ownership($model, $owner);
        $query = $model::query()->whereIn('name', $names);

        foreach ($scoped as $column => $value) {
            $query->where($column, $value);
        }

        $registered = $query->pluck('name')->all();
        $created = [];

        foreach (array_diff($names, $registered) as $name) {
            if ($model::query()->createOrFirst([...$scoped, 'name' => $name], $this->ownerColumns($owner))->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        return new SyncResult(
            created: $created,
            existing: array_values(array_diff($names, $created)),
        );
    }

    /**
     * The owner constraint for role rows while assignments are on: `''` for global roles, the
     * owner's key for owned ones. Permissions (and every row while assignments are off) have
     * no owner.
     *
     * @param  class-string<Role>|class-string<Permission>  $model
     * @return array<string, string>
     */
    private function ownership(string $model, ?Model $owner): array
    {
        if ($owner !== null) {
            AssignmentRows::assertEnabled();

            if (! is_a($model, Role::class, true)) {
                throw PermissionException::invalidType('Role');
            }

            return ['owner_key' => ScopeReference::of($owner)->ownerKey()];
        }

        return is_a($model, Role::class, true) && PermissionRegistrar::assignmentsEnabled() ? ['owner_key' => ''] : [];
    }

    /** @return array<string, string> */
    private function ownerColumns(?Model $owner): array
    {
        if ($owner === null) {
            return [];
        }

        $reference = ScopeReference::of($owner);

        return ['owner_type' => $reference->type, 'owner_id' => $reference->id];
    }
}
