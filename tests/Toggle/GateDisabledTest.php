<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

it('does not register the gate hook when disabled', function (): void {
    Permission::findOrCreate('auth.users.view');

    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('auth.users.view');

    // No gate check registered and no gate defined, so authorization is denied
    // even though the user holds the permission.
    expect(Gate::forUser($user)->allows('auth.users.view'))->toBeFalse();
});
