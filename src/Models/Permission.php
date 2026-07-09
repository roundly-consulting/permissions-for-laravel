<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Permissions\Database\Factories\PermissionFactory;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * @property int $id
 * @property string $name
 * @property array<string, string>|null $description
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return PermissionRegistrar::tableName('permissions', 'permissions');
    }

    public static function findOrCreate(string|BackedEnum $name): static
    {
        /** @var static $permission */
        $permission = static::query()->firstOrCreate(['name' => PermissionRegistrar::nameOf($name)]);

        return $permission;
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionRegistrar::roleModel(),
            PermissionRegistrar::tableName('permission_role', 'permission_role'),
            'permission_id',
            'role_id',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['description' => 'array'];
    }

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }
}
