<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A real Eloquent model that is NOT one of this package's models.
 *
 * The toolkit's ModelResolver only validates "is a Model"; the package's own
 * resolvers must additionally narrow to their base class, because the package
 * calls Role/Permission's own API. This fixture pins that edge.
 */
final class NotAPermission extends Model
{
    protected $guarded = [];

    protected $table = 'posts';
}
