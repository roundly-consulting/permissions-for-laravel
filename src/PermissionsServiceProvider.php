<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Permissions\Commands\CacheResetCommand;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

final class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permissions.php', 'permissions');

        $this->app->singleton(PermissionRegistrar::class);
    }

    public function boot(): void
    {
        if (config('permissions.load_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->registerModelEvents();
        $this->registerGateCheck();

        if ($this->app->runningInConsole()) {
            $this->commands([CacheResetCommand::class]);

            $this->publishes([
                __DIR__.'/../config/permissions.php' => config_path('permissions.php'),
            ], 'permissions-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'permissions-migrations');
        }
    }

    /**
     * Forget the cached catalog whenever a role or permission is saved or deleted,
     * so explicit invalidation calls are belt-and-braces rather than load-bearing.
     */
    private function registerModelEvents(): void
    {
        $forget = static function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };

        foreach ([PermissionRegistrar::roleModel(), PermissionRegistrar::permissionModel()] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }

    /**
     * Register a Gate::before hook so `can:<permission>` middleware and
     * `$user->can('<permission>')` resolve against granted permissions.
     */
    private function registerGateCheck(): void
    {
        if (! config('permissions.register_gate_check', true)) {
            return;
        }

        Gate::before(function (Authorizable $user, string $ability): ?bool {
            if (! method_exists($user, 'hasPermissionTo')) {
                return null; // not a role holder — let normal gates/policies run
            }

            if (! app(PermissionRegistrar::class)->permissionExists($ability)) {
                return null; // not one of our permissions — pass through (never false)
            }

            return $user->hasPermissionTo($ability) ? true : null;
        });
    }
}
