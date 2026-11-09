<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * One scoped and/or time-boxed role grant (`table_names.role_assignments`).
 *
 * Storage only: rows are written by the package's actions, which compute the `fingerprint` the
 * idempotent upsert keys on — write through `Permissions::for($holder)->in(…)`, never
 * `create()`. Host code reads grants through `Permissions::for($holder)->assignments()`.
 *
 * The morph types and the fingerprint are hidden, so a serialized row never carries a class name.
 *
 * Deliberate skill deviations (auditable on purpose):
 * - No SoftDeletes: a revoked grant must stop counting at once, and a trashed row would still
 *   hold its fingerprint, blocking the same grant from being made again.
 * - No factory: a row without its computed fingerprint is not a valid row.
 *
 * @property int $id
 * @property int|string $role_id
 * @property string $model_type
 * @property int|string $model_id
 * @property string|null $scope_type
 * @property int|string|null $scope_id
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property string $fingerprint
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 *
 * @internal
 */
final class RoleAssignment extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['model_type', 'scope_type', 'fingerprint'];

    public function getTable(): string
    {
        return PermissionRegistrar::roleAssignmentsTable();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(PermissionRegistrar::roleModel(), 'role_id');
    }

    /**
     * The scope model, or null for an unscoped grant.
     *
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo('scope', 'scope_type', 'scope_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => UtcDateTime::class,
            'valid_until' => UtcDateTime::class,
        ];
    }
}
