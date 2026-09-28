<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\PermissionModel;
use RoundlyConsulting\Permissions\Support\RoleModel;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomPermission;
use RoundlyConsulting\Permissions\Tests\Fixtures\CustomRole;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

/**
 * S — the model-swap proof, on the package the proof was BUILT FROM.
 *
 * `toHonourModelSwap` exists because of permissions #31/#34: `findOrCreate()` resolved
 * `static::query()` — the CALLED class rather than the CONFIGURED one — so a host that
 * swapped the model got rows created as the packaged class. Eloquent keys model events on
 * the concrete class, so the registrar's catalog-cache invalidation never fired and
 * **authorization broke**.
 *
 * The swap is applied by `ConfiguredTestCase` in `defineEnvironment()`, i.e. BEFORE the
 * providers boot — the only correct place, since the provider hangs its cache-invalidation
 * listeners on whatever class the config names at boot.
 *
 * The expectation is strictly stronger than the `instanceof` checks beside it in
 * ConfiguredModelsTest: it fails fast if the config does not already name the subclass (so
 * a forgotten before-boot swap is caught rather than silently proving nothing), asserts the
 * **concrete class** of every returned model, and asserts a `created` event landed on the
 * subclass itself — the only proof the row was really created AS the host's class rather
 * than merely cast to it.
 */
it('creates a role through the configured model, not the called class', function (): void {
    expect('permissions.models.role')->toHonourModelSwap(CustomRole::class, function (): array {
        // The README's documented entry point, called ON THE PACKAGED CLASS — which is
        // exactly how #34 hid: `static::` resolved to Role here, not to the host's class.
        $role = Role::findOrCreate('editor');

        // Read back through the seam, as the package's own code does. NOT `Role::query()`:
        // that is a direct Eloquent call on the packaged class, so it returns a packaged
        // Role by definition — a host reads through RoleModel, and so must this proof.
        return [$role, RoleModel::query()->where('name', 'editor')->first()];
    });
});

it('creates a permission through the configured model, not the called class', function (): void {
    expect('permissions.models.permission')->toHonourModelSwap(CustomPermission::class, function (): array {
        $permission = Permission::findOrCreate('posts.edit');

        // Read back through the seam, as the package's own code does (see above).
        return [$permission, PermissionModel::query()->where('name', 'posts.edit')->first()];
    });
});

/**
 * The read side: `findOrCreate` on an EXISTING row creates nothing, so the created-event
 * half must be waived explicitly rather than silently skipped. A row hydrated as the
 * packaged base class would still satisfy `instanceof` while never firing the host's
 * events.
 */
it('resolves an existing role through the configured model', function (): void {
    Role::findOrCreate('editor');

    expect('permissions.models.role')->toHonourModelSwap(
        CustomRole::class,
        fn (): array => [Role::findOrCreate('editor')],
        expectsCreation: false,
    );
});

/**
 * #34's actual consequence, pinned end to end: the row must be created as the host's class
 * **and** the gate must authorize immediately afterwards. That second half is what broke —
 * the catalog cache is invalidated by the configured model's `saved` event, so a row
 * created as the packaged class leaves a stale catalog and `$user->can(...)` returns false
 * for a permission that demonstrably exists.
 */
it('authorizes through the gate right after a swapped-model permission is created', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    // Warm the catalog the way any earlier gate check would, so a missing invalidation
    // leaves something stale to find.
    Permissions::permissions();

    expect('permissions.models.permission')->toHonourModelSwap(CustomPermission::class, function () use ($user): array {
        $permission = Permission::findOrCreate('posts.publish');
        $user->givePermissionTo('posts.publish');

        return [$permission];
    });

    expect($user->can('posts.publish'))->toBeTrue();
});
