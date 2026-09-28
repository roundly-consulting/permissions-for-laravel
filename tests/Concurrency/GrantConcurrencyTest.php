<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

/**
 * The toolkit's `LockedUpdate` was rejected for this package (it locks and mutates a
 * single existing row; this package only ever writes junction rows). These pins cover
 * the invariants that made the rejection safe — the additive registry's promise, and
 * the atomicity of the authoritative writes.
 */
beforeEach(function (): void {
    foreach (['posts.edit', 'posts.publish', 'posts.delete'] as $name) {
        Permission::findOrCreate($name);
    }
});

it('never detaches a grant another caller registered', function (): void {
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.edit');

    // A competing service registers its own permission on the same role, landing
    // between our read and our write. Additive grants must fold it in, never
    // overwrite it — this is the invariant the whole registry is sold on.
    Permission::query()->where('name', 'posts.publish')->first()?->roles()->attach($role->getKey());

    $role->givePermissionTo('posts.delete');

    expect($role->refresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['posts.delete', 'posts.edit', 'posts.publish']);
});

it('writes an additive grant without detaching anything', function (): void {
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.edit');

    DB::enableQueryLog();
    $role->givePermissionTo('posts.publish');
    $statements = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // syncWithoutDetaching, not sync: no DELETE may ever be emitted by an additive grant.
    expect($statements->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'delete')))
        ->toBeEmpty();
});

it('detaches and re-attaches an authoritative sync inside one transaction', function (): void {
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.edit', 'posts.publish');

    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains(strtolower($query->sql), 'permission_role')) {
            $levels[] = DB::transactionLevel();
        }
    });

    $role->syncPermissions(['posts.delete']);

    // Every pivot statement of the sync ran inside a transaction, so a concurrent
    // gate check can never observe the empty mid-sync grant set.
    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([1])
        ->and(DB::transactionLevel())->toBe(0);

    expect($role->refresh()->permissions->pluck('name')->all())->toBe(['posts.delete']);
});

it('detaches and re-attaches a role sync inside one transaction', function (): void {
    Role::findOrCreate('editor');
    Role::findOrCreate('viewer');

    $user = User::query()->create(['name' => 'ada']);
    $user->assignRole('editor');

    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains(strtolower($query->sql), 'model_roles')) {
            $levels[] = DB::transactionLevel();
        }
    });

    $user->syncRoles(['viewer']);

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([1])
        ->and($user->refresh()->getRoleNames()->all())->toBe(['viewer']);
});

it('detaches every grant atomically when a holder is torn down', function (): void {
    Role::findOrCreate('editor');

    $user = User::query()->create(['name' => 'ada']);
    $user->assignRole('editor');
    $user->givePermissionTo('posts.edit');

    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains(strtolower($query->sql), 'model_roles') || str_contains(strtolower($query->sql), 'model_permissions')) {
            $levels[] = DB::transactionLevel();
        }
    });

    $user->forgetAllAuthorization();

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([1])
        ->and($user->refresh()->getRoleNames()->all())->toBe([])
        ->and($user->getDirectPermissions()->all())->toBe([]);
});

it('invalidates the catalog after every grant mutation', function (): void {
    $role = Role::findOrCreate('editor');
    $user = User::query()->create(['name' => 'ada']);

    foreach ([
        fn () => $role->givePermissionTo('posts.edit'),
        fn () => $role->syncPermissions(['posts.publish']),
        fn () => $role->revokePermissionTo('posts.publish'),
        fn () => $user->assignRole('editor'),
        fn () => $user->syncRoles(['editor']),
        fn () => $user->removeRole('editor'),
        fn () => $user->forgetAllAuthorization(),
    ] as $mutation) {
        Permissions::permissions();
        app('cache')->put('permissions.cache', collect(), 300);

        $mutation();

        expect(app('cache')->get('permissions.cache'))->toBeNull();
    }
});
