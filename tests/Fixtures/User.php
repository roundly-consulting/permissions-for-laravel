<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RoundlyConsulting\Permissions\Concerns\HasRoles;

/**
 * @property int $id
 * @property string|null $name
 */
final class User extends Authenticatable
{
    use HasRoles;

    protected $guarded = [];

    protected $table = 'users';
}
