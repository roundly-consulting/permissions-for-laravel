<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\Permissions\Commands\CacheResetCommand;
use RoundlyConsulting\Permissions\Commands\PruneOrphansCommand;
use RoundlyConsulting\Permissions\Support\DescriptionFallback;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

final class PermissionsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('permissions')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasCommands([CacheResetCommand::class, PruneOrphansCommand::class])
            ->contributesToAbout(fn (): array => $this->aboutSection());
    }

    public function register(): void
    {
        parent::register();

        // Registered here, not in boot(), so the `ownerKey()` schema macro the
        // junction migrations call exists before the migrator can run.
        $this->registerBlueprintMacros();

        $this->app->singleton(PermissionRegistrar::class);
        $this->app->singleton(PermissionsManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerModelEvents();
        $this->registerTransactionSettling();
        $this->registerGateCheck();
        $this->registerMemoReset();
    }

    /**
     * The `about` payload.
     *
     * Secret-safe: a permission or role name is the host's own business
     * vocabulary, but this package's config ships none of them (they live in the
     * database), so there is nothing here to leak. What config *does* hold is
     * host topology — the cache store, the cache key and the table names — and
     * that renders as presence and counts only, never as a value.
     *
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $renamed = count(array_filter(
            [
                'roles' => PermissionRegistrar::rolesTable(),
                'permissions' => PermissionRegistrar::permissionsTable(),
                'permission_role' => PermissionRegistrar::permissionRoleTable(),
                'model_roles' => PermissionRegistrar::modelRolesTable(),
                'model_permissions' => PermissionRegistrar::modelPermissionsTable(),
            ],
            static fn (string $table, string $default): bool => $table !== $default,
            ARRAY_FILTER_USE_BOTH,
        ));

        $store = config('permissions.cache.store');
        $key = config('permissions.cache.key');
        $ttl = config('permissions.cache.ttl', 300);

        return [
            'Role model' => class_basename(PermissionRegistrar::roleModel()),
            'Permission model' => class_basename(PermissionRegistrar::permissionModel()),
            'Holder key type' => PermissionRegistrar::keyType()->value,
            'Description fallback' => DescriptionFallback::fromConfig()->value,
            'Tables' => $renamed === 0 ? 'DEFAULT' : $renamed.' renamed',
            'Gate check' => config('permissions.register_gate_check', true) === false ? 'OFF' : 'ON',
            'Catalog cache' => sprintf(
                'store %s, key %s, ttl %ds',
                $store === 'default' || ! is_string($store) ? 'DEFAULT' : 'SET',
                $key === 'permissions.cache' || ! is_string($key) ? 'DEFAULT' : 'SET',
                is_int($ttl) ? $ttl : 300,
            ),
        ];
    }

    /**
     * Forget the cached catalog whenever a role or permission is saved or deleted,
     * so explicit invalidation calls are belt-and-braces rather than load-bearing —
     * after the write commits, when it happens inside a transaction (see
     * {@see PermissionRegistrar::forgetAfterCommit()}).
     *
     * Straight to the registrar, not through the manager: this is the package's own
     * housekeeping, and routing it through `Permissions::cache()` would make the fake
     * record a cache flush on every role save.
     */
    private function registerModelEvents(): void
    {
        $forget = static function (Model $model): void {
            app(PermissionRegistrar::class)->forgetAfterCommit($model->getConnection());
        };

        foreach ([PermissionRegistrar::roleModel(), PermissionRegistrar::permissionModel()] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }
    }

    /**
     * Tell the registrar when a transaction commits or rolls back, so a catalog write made
     * inside one invalidates the shared cache once it is visible to every process.
     *
     * A registrar that was never resolved has nothing pending, so it is not built here.
     */
    private function registerTransactionSettling(): void
    {
        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            if (app()->resolved(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->settle($event->connection, committed: true);
            }
        });

        Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            if (app()->resolved(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->settle($event->connection, committed: false);
            }
        });
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

            if (! app(PermissionsManager::class)->exists($ability)) {
                return null; // not one of our permissions — pass through (never false)
            }

            return $user->hasPermissionTo($ability) ? true : null;
        });
    }

    /**
     * Reset the registrar's in-process memo at request/job boundaries so a
     * long-lived worker (Octane, queue) never serves a memo that outlived its
     * authority. The shared cache store remains the source of truth. Straight to
     * the registrar for the same reason as the model events above.
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
