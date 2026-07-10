<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A policy-covered model used to prove the Gate::before hook defers to policies
 * when an argument is present.
 *
 * @property int $id
 * @property int|null $owner_id
 */
final class Post extends Model
{
    protected $guarded = [];

    protected $table = 'posts';

    public $timestamps = false;
}
