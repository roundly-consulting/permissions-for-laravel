<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Permissions\PermissionsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Translatable\TranslatableServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider permissions hard-requires, in registration order. A host
     * auto-discovers these; the suite must list them or the test environment is a
     * fiction.
     *
     * permissions is a translatable consumer: the `description` attribute resolves
     * through translatable's HasTranslations trait, so a real host has its provider
     * booted (config + blueprint macros). Translatable ships no migrations, so there is
     * nothing to load by directory for it.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            TranslatableServiceProvider::class,
            PermissionsServiceProvider::class,
        ];
    }

    /**
     * The five permission/role tables, named by provider class (never by filename),
     * plus the host-owned `users` fixture the roles and permissions are granted to.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            __DIR__.'/database/migrations',
            PermissionsServiceProvider::class,
        ];
    }

    /**
     * Applied BEFORE the providers boot.
     *
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return ['cache.default' => 'array'];
    }
}
