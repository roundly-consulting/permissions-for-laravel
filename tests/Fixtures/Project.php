<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string|null $name
 * @property Tenant|null $tenant
 */
final class Project extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'projects';

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
