<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $name
 */
final class Tenant extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'tenants';
}
