<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permission::findOrCreate('auth.users.view');
    Role::findOrCreate('administrator');
});

it('forgets all role and permission grants for a model', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->assignRole('administrator');
    $user->givePermissionTo('auth.users.view');

    $user->forgetAllAuthorization();

    expect($user->getRoleNames()->all())->toBe([])
        ->and($user->getDirectPermissions()->all())->toBe([])
        ->and(DB::table('model_roles')->count())->toBe(0)
        ->and(DB::table('model_permissions')->count())->toBe(0);
});

it('prunes only pivot rows whose holder was deleted', function (): void {
    $kept = User::create(['name' => 'Kept']);
    $gone = User::create(['name' => 'Gone']);
    $kept->assignRole('administrator');
    $gone->assignRole('administrator');
    $gone->givePermissionTo('auth.users.view');

    // Bulk delete the holder — no model events, no cleanup hook.
    DB::table('users')->where('id', $gone->getKey())->delete();

    expect(DB::table('model_roles')->count())->toBe(2)
        ->and(DB::table('model_permissions')->count())->toBe(1);

    $this->artisan('permissions:prune-orphans')
        ->expectsOutputToContain('Pruned 2 orphaned authorization row(s).')
        ->assertExitCode(0);

    expect(DB::table('model_roles')->pluck('model_id')->all())->toBe([$kept->getKey()])
        ->and(DB::table('model_permissions')->count())->toBe(0);
});

it('prunes rows whose morph type no longer resolves to a model', function (): void {
    $role = Role::findOrCreate('administrator');

    DB::table('model_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => 'App\\Models\\Ghost',
        'model_id' => 1,
    ]);

    $this->artisan('permissions:prune-orphans')->assertExitCode(0);

    expect(DB::table('model_roles')->count())->toBe(0);
});
