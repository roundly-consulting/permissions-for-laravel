<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Permissions\Concerns\HasPermissions;
use RoundlyConsulting\Permissions\Concerns\TranslatesDescription;
use RoundlyConsulting\Permissions\Database\Factories\RoleFactory;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description Resolved for the current locale; assign a per-locale array to write.
 * @property string|null $owner_type 2.1, with assignments on: the owner's morph class (hidden)
 * @property int|string|null $owner_id 2.1, with assignments on: the owner's key
 * @property string|null $owner_key 2.1, with assignments on: `''` for a global role (hidden)
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

    /**
     * The 2.1 owner columns carry a class name (`owner_type`, and `owner_key` embeds it), so a
     * serialized role never shows them. Absent until the 2.1 migrations run.
     *
     * @var list<string>
     */
    protected $hidden = ['owner_type', 'owner_key'];

    public function getTable(): string
    {
        return PermissionRegistrar::rolesTable();
    }

    /**
     * Idempotently register a role, returning the model configured at
     * `permissions.models.role`.
     *
     * Sugar for `Permissions::role($name)`: resolved through the config seam
     * rather than `static::`, so a host that swapped the model gets *its* class
     * back — and the row is created as that class, which is what fires the model
     * events the provider hangs the catalog cache invalidation on.
     */
    public static function findOrCreate(string|BackedEnum $name): self
    {
        return app(PermissionsManager::class)->role($name);
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

    /**
     * The model that owns this role (`Permissions::owner($tenant)->role(…)`), or null for a
     * global role. Only meaningful once the 2.1 migrations ran.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    /** Whether this is a tenant-defined role (owned) rather than a global one. */
    public function isOwned(): bool
    {
        $key = $this->getAttribute('owner_key');

        return is_string($key) && $key !== '';
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
