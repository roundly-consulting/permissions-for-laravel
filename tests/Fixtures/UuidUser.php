<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use RoundlyConsulting\Permissions\Concerns\HasRoles;

/**
 * A uuid-keyed holder, for the mixed key-type migration tests.
 *
 * @property string $id
 */
final class UuidUser extends Authenticatable
{
    use HasRoles;
    use HasUuids;

    protected $guarded = [];

    protected $table = 'uuid_users';
}
