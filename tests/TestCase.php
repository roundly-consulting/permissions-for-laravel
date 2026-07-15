<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Permissions\PermissionsServiceProvider;
use RoundlyConsulting\Translatable\TranslatableServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // permissions is a translatable consumer: the description attribute resolves
        // through translatable's HasTranslations trait, so a real host has its
        // provider booted (config + blueprint macros). Translatable ships no
        // migrations, so there is nothing to load by directory here.
        return [
            TranslatableServiceProvider::class,
            PermissionsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->beforeApplicationDestroyed(function (): void {
            Schema::dropIfExists('users');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
