<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Database\Factories\RoleFactory;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * @property int $id
 * @property string $name
 * @property array<string, string>|null $description
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasPermissions;

    protected $guarded = [];

    public function getTable(): string
    {
        return PermissionRegistrar::tableName('roles', 'roles');
    }

    public static function findOrCreate(string|BackedEnum $name): static
    {
        /** @var static $role */
        $role = static::query()->firstOrCreate(['name' => PermissionRegistrar::nameOf($name)]);

        return $role;
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionRegistrar::permissionModel(),
            PermissionRegistrar::tableName('permission_role', 'permission_role'),
            'role_id',
            'permission_id',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['description' => 'array'];
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
