<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A uuid-keyed scope, for the mixed key-type migration tests.
 *
 * @property string $id
 */
final class UuidProject extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'uuid_projects';
}
