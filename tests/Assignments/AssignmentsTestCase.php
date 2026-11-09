<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Assignments;

use RoundlyConsulting\Permissions\Tests\TestCase;

/**
 * The 2.1 surface: `permissions.assignments.enabled` on, the 2.1 migrations applied (the base
 * case always applies every migration).
 */
abstract class AssignmentsTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'permissions.assignments.enabled' => true,
        ];
    }
}
