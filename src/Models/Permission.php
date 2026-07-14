<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Permissions\Database\Factories\PermissionFactory;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * @property int $id
 * @property string $name
 * @property array<string, string>|null $description
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 *
 * Deliberate skill deviations (auditable on purpose):
 * - Non-final: the model is swappable via `permissions.models.permission`, so a
 *   host may extend it — a final class would forbid that config contract.
 * - No SoftDeletes: the `unique('name')` index would clash with soft-deleted
 *   rows, and the junction pivots `cascadeOnDelete`, so deletion is hard by design.
 */
class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return PermissionRegistrar::permissionsTable();
    }

    /**
     * Idempotently register a permission, returning the model configured at
     * `permissions.models.permission`.
     *
     * Resolved through the config seam rather than `static::`, so a host that
     * swapped the model gets *its* class back — and the row is created as that
     * class, which is what fires the model events the provider hangs the catalog
     * cache invalidation on.
     */
    public static function findOrCreate(string|BackedEnum $name): self
    {
        return PermissionModel::query()->createOrFirst(['name' => PermissionRegistrar::nameOf($name)]);
    }

    /**
     * The localized description for the given (or current) locale, or null.
     */
    public function description(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $description = $this->description;

        return is_array($description) ? ($description[$locale] ?? null) : null;
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionRegistrar::roleModel(),
            PermissionRegistrar::permissionRoleTable(),
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
