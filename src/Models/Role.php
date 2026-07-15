<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Concerns\TranslatesDescription;
use RoundlyConsulting\Permissions\Database\Factories\RoleFactory;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\RoleModel;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description Resolved for the current locale; assign a per-locale array to write.
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 *
 * Deliberate skill deviations (auditable on purpose):
 * - Non-final: the model is swappable via `permissions.models.role`, so a host
 *   may extend it — a final class would forbid that config contract.
 * - No SoftDeletes: the `unique('name')` index would clash with soft-deleted
 *   rows, and the junction pivots `cascadeOnDelete`, so deletion is hard by design.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasPermissions;
    use TranslatesDescription;

    protected $guarded = [];

    public function getTable(): string
    {
        return PermissionRegistrar::rolesTable();
    }

    /**
     * Idempotently register a role, returning the model configured at
     * `permissions.models.role`.
     *
     * Resolved through the config seam rather than `static::`, so a host that
     * swapped the model gets *its* class back — and the row is created as that
     * class, which is what fires the model events the provider hangs the catalog
     * cache invalidation on.
     */
    public static function findOrCreate(string|BackedEnum $name): self
    {
        return RoleModel::query()->createOrFirst(['name' => PermissionRegistrar::nameOf($name)]);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionRegistrar::permissionModel(),
            PermissionRegistrar::permissionRoleTable(),
            'role_id',
            'permission_id',
        );
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
