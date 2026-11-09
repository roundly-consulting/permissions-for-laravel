<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Enums;

use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Permissions\DataTransferObjects\Assignment;

/** What an {@see Assignment} grants. */
enum AssignmentKind: string
{
    use Helpers;

    case Role = 'role';
    case Permission = 'permission';
}
