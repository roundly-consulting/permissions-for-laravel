<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Configured;

use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\TestCase;

/**
 * Boots the app with both models swapped **before the provider boots**, which is
 * what a real host does. That ordering matters: the provider hangs the catalog
 * cache invalidation on the *configured* model's `saved`/`deleted` events, so a
 * package that quietly creates rows as its own packaged class never fires them.
 */
abstract class ConfiguredTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('permissions.models.role', CustomRole::class);
        $app['config']->set('permissions.models.permission', CustomPermission::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        CustomRole::$creationCount = 0;
        CustomPermission::$creationCount = 0;
    }
}
