<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Permission::findOrCreate('auth.users.edit');
    Role::findOrCreate('administrator');
});

it('allows a granted permission through the gate', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('auth.users.view');

    expect(Gate::forUser($user)->allows('auth.users.view'))->toBeTrue();
});

it('denies an existing but ungranted permission', function (): void {
    $user = User::create(['name' => 'Ada']);

    expect(Gate::forUser($user)->allows('auth.users.view'))->toBeFalse();
});

it('passes through unknown abilities to custom gates', function (): void {
    Gate::define('view-horizon', fn (): bool => true);

    $user = User::create(['name' => 'Ada']);

    expect(Gate::forUser($user)->allows('view-horizon'))->toBeTrue();
});

it('never blocks a non-permission ability defined elsewhere', function (): void {
    Gate::define('custom-ability', fn (): bool => false);

    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('auth.users.view');

    // The Gate::before hook returns null for non-permission abilities, so the
    // explicit gate still decides.
    expect(Gate::forUser($user)->allows('custom-ability'))->toBeFalse();
});

it('lets a resolved permission via a role authorize', function (): void {
    $role = Role::findOrCreate('administrator');
    $role->givePermissionTo('auth.users.edit');

    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');

    expect(Gate::forUser($user)->allows('auth.users.edit'))->toBeTrue();
});

it('passes through for a user without the trait', function (): void {
    Gate::define('auth.users.view', fn (): bool => true);

    $plain = new class extends Illuminate\Foundation\Auth\User {};

    expect(Gate::forUser($plain)->allows('auth.users.view'))->toBeTrue();
});
