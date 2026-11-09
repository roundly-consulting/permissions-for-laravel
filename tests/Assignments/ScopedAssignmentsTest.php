<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\Team;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    foreach (['posts.edit', 'posts.publish', 'posts.view', 'reports.export'] as $name) {
        Permissions::permission($name);
    }

    Permissions::role('editor')->givePermissionTo('posts.edit', 'posts.view');
    Permissions::role('reviewer')->givePermissionTo('posts.publish');
    Permissions::role('viewer')->givePermissionTo('posts.view');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->a = Project::query()->create(['name' => 'A']);
    $this->b = Project::query()->create(['name' => 'B']);
});

it('holds several roles inside one scope', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor', RoleName::Editor, ['reviewer']);

    $here = Permissions::for($this->user)->in($this->a);

    expect($here->hasRole('editor'))->toBeTrue()
        ->and($here->hasRole('reviewer'))->toBeTrue()
        ->and($here->hasAnyRole('ghost', 'reviewer'))->toBeTrue()
        ->and($here->hasRole('viewer'))->toBeFalse()
        ->and($here->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($here->hasPermissionTo('posts.publish'))->toBeTrue()
        ->and($here->roleNames()->sort()->values()->all())->toBe(['editor', 'reviewer'])
        ->and($here->permissionNames()->sort()->values()->all())->toBe(['posts.edit', 'posts.publish', 'posts.view'])
        ->and(DB::table('role_assignments')->count())->toBe(2);
});

it('keeps a grant in scope A invisible in scope B', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');

    expect(Permissions::for($this->user)->in($this->b)->hasRole('editor'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->b)->hasPermissionTo('posts.edit'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->b)->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->a)->hasPermissionTo('reports.export'))->toBeTrue();
});

it('tells apart two scopes with the same key but a different model', function (): void {
    $team = Team::query()->create(['name' => 'same id']);
    expect($team->getKey())->toBe($this->a->getKey());

    Permissions::for($this->user)->in($team)->assignRole('editor');

    expect(Permissions::for($this->user)->in($team)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::for($this->user)->in($this->a)->hasRole('editor'))->toBeFalse();
});

it('answers a check in several scopes as any of them', function (): void {
    Permissions::for($this->user)->in($this->b)->assignRole('editor');

    expect(Permissions::for($this->user)->in([$this->a, $this->b])->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($this->user->hasRoleIn([$this->a, $this->b], 'editor'))->toBeTrue()
        ->and($this->user->hasRoleIn($this->a, 'editor'))->toBeFalse()
        ->and($this->user->hasPermissionIn(collect([$this->b]), PermissionName::ViewUsers))->toBeFalse()
        ->and($this->user->hasPermissionIn($this->b, 'posts.view'))->toBeTrue();
});

it('never lets a scoped grant satisfy an unscoped check', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');

    $fresh = User::query()->findOrFail($this->user->getKey());

    expect($fresh->hasRole('editor'))->toBeFalse()
        ->and($fresh->hasPermissionTo('posts.edit'))->toBeFalse()
        ->and($fresh->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Permissions::for($fresh)->hasRole('editor'))->toBeFalse()
        ->and(Permissions::for($fresh)->hasPermissionTo('reports.export'))->toBeFalse()
        ->and($fresh->can('posts.edit'))->toBeFalse();
});

it('applies global grants inside every scope by default', function (): void {
    $this->user->assignRole('editor');                                         // 2.0 grant
    Permissions::for($this->user)->until(now()->addDay())->givePermissionTo('reports.export'); // unscoped, time-boxed

    expect(Permissions::for($this->user)->in($this->a)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::for($this->user)->in($this->a)->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and(Permissions::for($this->user)->in($this->a)->hasPermissionTo('reports.export'))->toBeTrue();
});

it('keeps global grants out of scopes when global_applies_in_scope is off', function (): void {
    config()->set('permissions.assignments.global_applies_in_scope', false);

    $this->user->assignRole('editor');
    Permissions::for($this->user)->until(now()->addDay())->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->a)->assignRole('viewer');

    $here = Permissions::for($this->user)->in($this->a);

    expect($here->hasRole('editor'))->toBeFalse()
        ->and($here->hasPermissionTo('posts.edit'))->toBeFalse()
        ->and($here->hasPermissionTo('reports.export'))->toBeFalse()
        ->and($here->hasRole('viewer'))->toBeTrue()
        ->and($here->roleNames()->all())->toBe(['viewer'])
        ->and($this->user->hasRole('editor'))->toBeTrue() // unscoped checks still see them
        ->and($this->user->hasPermissionTo('reports.export'))->toBeTrue();
});

it('assigns idempotently: the same assignment twice is one row', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->assignRole('editor', 'editor');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');

    expect(DB::table('role_assignments')->count())->toBe(1)
        ->and(DB::table('permission_assignments')->count())->toBe(1);
});

it('syncs the complete set of one scope only', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor', 'reviewer');
    Permissions::for($this->user)->in($this->b)->assignRole('editor');
    $this->user->assignRole('reviewer');

    Permissions::for($this->user)->in($this->a)->syncRoles(['viewer']);

    expect(Permissions::for($this->user)->in($this->a)->roleNames()->sort()->values()->all())->toBe(['reviewer', 'viewer'])
        ->and(Permissions::for($this->user)->in($this->b)->hasRole('editor'))->toBeTrue()
        ->and($this->user->refresh()->hasRole('reviewer'))->toBeTrue();

    config()->set('permissions.assignments.global_applies_in_scope', false);
    expect(Permissions::for($this->user)->in($this->a)->roleNames()->all())->toBe(['viewer']);
});

it('syncs direct permissions of one scope only', function (): void {
    Permissions::for($this->user)->in($this->a)->givePermissionTo('posts.edit', 'posts.publish');
    Permissions::for($this->user)->in($this->b)->givePermissionTo('posts.edit');

    Permissions::for($this->user)->in($this->a)->syncPermissions(['reports.export']);

    config()->set('permissions.assignments.global_applies_in_scope', false);

    expect(Permissions::for($this->user)->in($this->a)->permissionNames()->all())->toBe(['reports.export'])
        ->and(Permissions::for($this->user)->in($this->b)->permissionNames()->all())->toBe(['posts.edit']);
});

it('removes every window of a role in one scope, and nothing elsewhere', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->until(now()->addWeek())->assignRole('editor');
    Permissions::for($this->user)->in($this->b)->assignRole('editor');

    Permissions::for($this->user)->in($this->a)->removeRole('editor');

    expect(Permissions::for($this->user)->in($this->a)->hasRole('editor'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->b)->hasRole('editor'))->toBeTrue()
        ->and(DB::table('role_assignments')->count())->toBe(1);
});

it('revokes direct permissions in one scope only', function (): void {
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->b)->givePermissionTo('reports.export');

    Permissions::for($this->user)->in($this->a)->revokePermissionTo('reports.export');

    expect(Permissions::for($this->user)->in($this->a)->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->b)->hasPermissionTo('reports.export'))->toBeTrue();
});

it('forgets one scope, or the holder everywhere', function (): void {
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->b)->assignRole('editor');
    Permissions::for($this->user)->until(now()->addDay())->assignRole('viewer');
    $this->user->assignRole('reviewer');

    Permissions::for($this->user)->in($this->a)->forgetAllAuthorization();

    expect(Permissions::for($this->user)->in($this->a)->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->b)->hasRole('editor'))->toBeTrue()
        ->and($this->user->hasRole('viewer'))->toBeTrue();

    // The teardown verb clears every scope, so a reused key inherits nothing.
    $this->user->forgetAllAuthorization();

    expect(DB::table('role_assignments')->count())->toBe(0)
        ->and(DB::table('permission_assignments')->count())->toBe(0)
        ->and(DB::table('model_roles')->count())->toBe(0);
});

it('lets the unscoped 2.0 verbs clear unscoped assignment rows but never scoped ones', function (): void {
    Permissions::for($this->user)->until(now()->addDay())->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($this->user)->until(now()->addDay())->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->a)->givePermissionTo('reports.export');

    $this->user->removeRole('editor');
    $this->user->revokePermissionTo('reports.export');

    expect($this->user->hasRole('editor'))->toBeFalse()
        ->and($this->user->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->a)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::for($this->user)->in($this->a)->hasPermissionTo('reports.export'))->toBeTrue();

    Permissions::for($this->user)->until(now()->addDay())->assignRole('reviewer');
    Permissions::for($this->user)->until(now()->addDay())->givePermissionTo('posts.publish');
    $this->user->syncRoles(['viewer']);
    $this->user->syncPermissions([]);

    expect($this->user->hasRole('reviewer'))->toBeFalse()
        ->and($this->user->hasRole('viewer'))->toBeTrue()
        ->and($this->user->getDirectPermissions())->toBeEmpty()
        ->and(DB::table('role_assignments')->whereNull('scope_type')->count())->toBe(0)
        ->and(DB::table('permission_assignments')->whereNull('scope_type')->count())->toBe(0)
        ->and(DB::table('role_assignments')->whereNotNull('scope_type')->count())->toBe(1);
});

it('makes an unscoped time-boxed sync the complete unscoped set', function (): void {
    $this->user->assignRole('editor');
    $this->user->givePermissionTo('posts.edit');

    Permissions::for($this->user)->until(now()->addDay())->syncRoles(['viewer']);
    Permissions::for($this->user)->until(now()->addDay())->syncPermissions(['reports.export']);

    $fresh = User::query()->findOrFail($this->user->getKey());

    expect($fresh->getRoleNames()->all())->toBe([])
        ->and($fresh->hasRole('editor'))->toBeFalse()
        ->and($fresh->hasRole('viewer'))->toBeTrue()
        ->and($fresh->hasPermissionTo('posts.edit'))->toBeFalse()
        ->and($fresh->hasPermissionTo('reports.export'))->toBeTrue();
});

it('finds holders by role inside a scope with the roleIn query scope', function (): void {
    $other = User::query()->create(['name' => 'bob']);
    $global = User::query()->create(['name' => 'cy']);
    $expired = User::query()->create(['name' => 'dee']);

    Permissions::for($this->user)->in($this->a)->assignRole('editor');
    Permissions::for($other)->in($this->b)->assignRole('editor');
    $global->assignRole('editor');
    Permissions::for($expired)->in($this->a)->until(now()->addHour())->assignRole('editor');
    $this->travel(2)->hours();

    expect(User::query()->roleIn($this->a, 'editor')->pluck('name')->sort()->values()->all())->toBe(['ada', 'cy'])
        ->and(User::query()->roleIn([$this->a, $this->b], ['editor'])->count())->toBe(3)
        ->and(User::query()->roleIn($this->a, 'viewer')->count())->toBe(0);

    config()->set('permissions.assignments.global_applies_in_scope', false);

    expect(User::query()->roleIn($this->a, RoleName::Editor)->pluck('name')->all())->toBe(['ada']);
});

it('keeps the 2.0 role query scope on global roles', function (): void {
    $this->user->assignRole('editor');
    Permissions::for($this->user)->in($this->a)->assignRole('reviewer');

    expect(User::query()->role('editor')->count())->toBe(1)
        ->and(User::query()->role('reviewer')->count())->toBe(0);
});

it('refuses a write in more than one scope, an empty scope list and a non-model scope', function (): void {
    expect(fn () => Permissions::for($this->user)->in([$this->a, $this->b])->assignRole('editor'))
        ->toThrow(PermissionException::class, 'exactly one scope, 2 given')
        ->and(fn () => Permissions::for($this->user)->in([])->hasRole('editor'))
        ->toThrow(PermissionException::class, 'must not be empty')
        ->and(fn () => Permissions::for($this->user)->in(['project-1'])->hasRole('editor'))
        ->toThrow(PermissionException::class, 'must be an Eloquent model, string given')
        ->and(fn () => Permissions::for($this->user)->in(new Project)->hasRole('editor'))
        ->toThrow(PermissionException::class, 'unsaved')
        ->and(fn () => Permissions::scope([]))
        ->toThrow(PermissionException::class, 'must not be empty');
});

it('refuses scoped and time-boxed grants on a role holder', function (): void {
    $role = Permissions::findRole('editor');

    expect(fn () => Permissions::for($role)->in($this->a))
        ->toThrow(PermissionException::class, 'cannot hold scoped or time-boxed grants')
        ->and(fn () => Permissions::for($role)->until(now()->addDay()))
        ->toThrow(PermissionException::class, 'cannot hold scoped or time-boxed grants');
});

it('throws on unknown names before writing anything', function (): void {
    expect(fn () => Permissions::for($this->user)->in($this->a)->assignRole('editor', 'ghost'))
        ->toThrow(RoleDoesNotExist::class)
        ->and(fn () => Permissions::for($this->user)->in($this->a)->givePermissionTo('ghost'))
        ->toThrow(PermissionDoesNotExist::class)
        ->and(fn () => Permissions::for($this->user)->in($this->a)->removeRole('ghost'))
        ->toThrow(RoleDoesNotExist::class)
        ->and(DB::table('role_assignments')->count())->toBe(0);
});

it('cannot take scope or window as named arguments to the variadic writers', function (): void {
    // The pitfall that made the API fluent: `assignRole('editor', scope: $p)` lands the
    // project in `...$roles` and fails loudly instead of granting globally.
    expect(fn () => $this->user->assignRole('editor', scope: $this->a))->toThrow(TypeError::class)
        ->and(fn () => Permissions::for($this->user)->givePermissionTo('posts.edit', until: now()))->toThrow(TypeError::class)
        ->and(DB::table('model_roles')->count())->toBe(0)
        ->and(DB::table('model_permissions')->count())->toBe(0);
});

it('goes back to unscoped with in(null)', function (): void {
    $this->user->assignRole('editor');

    $handle = Permissions::for($this->user)->in($this->a)->in(null);

    expect($handle->hasRole('editor'))->toBeTrue();
    $handle->assignRole('viewer');
    expect(DB::table('model_roles')->count())->toBe(2)
        ->and(DB::table('role_assignments')->count())->toBe(0);
});

it('lets a Role holder read through the handle', function (): void {
    $role = Permissions::findRole('editor');

    expect(Permissions::for($role)->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and(Permissions::for($role)->permissionNames()->sort()->values()->all())->toBe(['posts.edit', 'posts.view'])
        ->and(fn () => Permissions::for($role)->hasRole('editor'))->toThrow(PermissionException::class, 'cannot hold roles');
});
