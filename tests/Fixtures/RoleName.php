<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

enum RoleName: string
{
    case Administrator = 'administrator';
    case Editor = 'editor';
}
