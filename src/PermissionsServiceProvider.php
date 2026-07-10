<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Permissions\Commands\CacheResetCommand;
use RoundlyConsulting\Permissions\Commands\PruneOrphansCommand;
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
        $this->registerMemoReset();

        if ($this->app->runningInConsole()) {
            $this->commands([CacheResetCommand::class, PruneOrphansCommand::class]);

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

        Gate::before(function (Authorizable $user, string $ability, array $arguments = []): ?bool {
            if ($arguments !== []) {
                // A model/argument was passed (e.g. `$user->can('update', $post)`),
                // so this is a policy-scoped check. Defer to the gate/policy — a
                // coarse permission of the same name must never override ownership
                // or tenancy checks.
                return null;
            }

            if (! method_exists($user, 'hasPermissionTo')) {
                return null; // not a role holder — let normal gates/policies run
            }

            if (! app(PermissionRegistrar::class)->permissionExists($ability)) {
                return null; // not one of our permissions — pass through (never false)
            }

            return $user->hasPermissionTo($ability) ? true : null;
        });
    }

    /**
     * Reset the registrar's in-process memo at request/job boundaries so a
     * long-lived worker (Octane, queue) never serves a memo that outlived its
     * authority. The shared cache store remains the source of truth.
     */
    private function registerMemoReset(): void
    {
        $flush = static function (): void {
            app(PermissionRegistrar::class)->flushMemo();
        };

        // Octane request/task/tick boundaries — matched by event class name so we
        // never take a runtime dependency on laravel/octane.
        foreach ([
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ] as $octaneEvent) {
            Event::listen($octaneEvent, $flush);
        }

        // Queue worker job boundary.
        Event::listen(JobProcessing::class, $flush);
    }
}
