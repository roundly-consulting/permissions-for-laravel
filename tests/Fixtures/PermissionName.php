<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Enums\Helpers;

/**
 * A consumer-defined permission enum, proving `string|BackedEnum` acceptance.
 */
enum PermissionName: string
{
    use Helpers;

    case ViewUsers = 'auth.users.view';
    case EditUsers = 'auth.users.edit';
}
