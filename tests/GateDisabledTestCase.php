<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests;

/**
 * Boots the package with the Gate::before hook disabled, so the toggle is
 * exercised at registration time rather than by re-booting the provider.
 */
abstract class GateDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('permissions.register_gate_check', false);
    }
}
