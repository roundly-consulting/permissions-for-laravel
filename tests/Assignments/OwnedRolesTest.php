<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\Tenant;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permissions::permission('posts.edit');
    Permissions::permission('posts.review');
    Permissions::permission('posts.view');

    $this->acme = Tenant::query()->create(['name' => 'acme']);
    $this->globex = Tenant::query()->create(['name' => 'globex']);
    $this->user = User::query()->create(['name' => 'ada']);
});

it('lets two owners each define a role of the same name, beside the global one', function (): void {
    $global = Permissions::role('editor')->givePermissionTo('posts.view');
    $acme = Permissions::owner($this->acme)->role('editor')->givePermissionTo('posts.edit');
    $globex = Permissions::owner($this->globex)->role('editor')->givePermissionTo('posts.review');

    expect(collect([$global, $acme, $globex])->map->getKey()->unique())->toHaveCount(3)
        ->and($acme->isOwned())->toBeTrue()
        ->and($global->isOwned())->toBeFalse()
        ->and($acme->owner?->is($this->acme))->toBeTrue()
        ->and(Permissions::owner($this->acme)->role('editor')->is($acme))->toBeTrue() // idempotent
        ->and(Permissions::owner($this->acme)->findRole('editor')?->is($acme))->toBeTrue()
        ->and(Permissions::owner($this->acme)->findRole('ghost'))->toBeNull()
        ->and(Permissions::findRole('editor')?->is($global))->toBeTrue()
        ->and(Permissions::roles()->pluck('name')->all())->toBe(['editor'])
        ->and(Permissions::role('editor')->is($global))->toBeTrue();
});

it('resolves a name to the scope owner\'s role before the global one', function (): void {
    Permissions::role('editor')->givePermissionTo('posts.view');
    Permissions::owner($this->acme)->role('editor')->givePermissionTo('posts.edit');

    Permissions::for($this->user)->in($this->acme)->assignRole('editor');

    $here = Permissions::for($this->user)->in($this->acme);

    expect($here->hasPermissionTo('posts.edit'))->toBeTrue()
        ->and($here->hasPermissionTo('posts.view'))->toBeFalse()
        ->and(Permissions::for($this->user)->in($this->globex)->hasRole('editor'))->toBeFalse();
});

it('falls back to the global role when the owner has none of that name', function (): void {
    Permissions::role('viewer')->givePermissionTo('posts.view');

    Permissions::for($this->user)->in($this->acme)->assignRole('viewer');

    expect(Permissions::for($this->user)->in($this->acme)->hasPermissionTo('posts.view'))->toBeTrue();
});

it('refuses an owned role outside its owner\'s scope', function (): void {
    $acme = Permissions::owner($this->acme)->role('reviewer');
    $project = Project::query()->create(['tenant_id' => $this->acme->getKey()]);

    expect(fn () => Permissions::for($this->user)->in($this->globex)->assignRole($acme))
        ->toThrow(PermissionException::class, 'belongs to another owner')
        ->and(fn () => Permissions::for($this->user)->in($project)->assignRole($acme))
        ->toThrow(PermissionException::class, 'belongs to another owner')
        ->and(fn () => $this->user->assignRole($acme))
        ->toThrow(PermissionException::class, 'belongs to another owner')
        ->and(fn () => Permissions::for($this->user)->until(now()->addDay())->assignRole($acme))
        ->toThrow(PermissionException::class, 'belongs to another owner')
        ->and(fn () => Permissions::for($this->user)->in($project)->assignRole('reviewer'))
        ->toThrow(RoleDoesNotExist::class)
        ->and(DB::table('role_assignments')->count())->toBe(0);
});

it('widens an owner\'s roles to its sub-scopes with resolveRoleOwnerUsing', function (): void {
    Permissions::owner($this->acme)->role('reviewer')->givePermissionTo('posts.review');
    $project = Project::query()->create(['tenant_id' => $this->acme->getKey()]);
    $foreign = Project::query()->create(['tenant_id' => $this->globex->getKey()]);

    Permissions::resolveRoleOwnerUsing(static fn (Model $scope): ?Model => $scope instanceof Project ? $scope->tenant : null);

    Permissions::for($this->user)->in($project)->assignRole('reviewer');

    expect(Permissions::for($this->user)->in($project)->hasPermissionTo('posts.review'))->toBeTrue()
        ->and(fn () => Permissions::for($this->user)->in($foreign)->assignRole('reviewer'))
        ->toThrow(RoleDoesNotExist::class);

    Permissions::resolveRoleOwnerUsing(null);

    expect(fn () => Permissions::for($this->user)->in($project)->assignRole('reviewer'))
        ->toThrow(RoleDoesNotExist::class);
});

it('refuses a role-owner resolver that returns something other than a model', function (): void {
    Permissions::role('viewer');
    Permissions::resolveRoleOwnerUsing(static fn (Model $scope): string => 'acme');

    expect(fn () => Permissions::for($this->user)->in($this->acme)->assignRole('viewer'))
        ->toThrow(PermissionException::class, 'must be an Eloquent model, string given');
});

it('removes every role a name can mean in the scope', function (): void {
    $global = Permissions::role('editor');
    Permissions::owner($this->acme)->role('editor');

    Permissions::for($this->user)->in($this->acme)->assignRole('editor', $global);
    expect(DB::table('role_assignments')->count())->toBe(2);

    Permissions::for($this->user)->in($this->acme)->removeRole('editor');

    expect(DB::table('role_assignments')->count())->toBe(0);
});

it('lists, syncs from an enum and never duplicates an owner\'s roles', function (): void {
    $result = Permissions::owner($this->acme)->syncRolesFrom(RoleName::class);
    $again = Permissions::owner($this->acme)->syncRolesFrom(RoleName::class);
    Permissions::syncRolesFrom(RoleName::class);

    expect($result->created)->toBe(['administrator', 'editor'])
        ->and($again->created)->toBe([])
        ->and($again->existing)->toBe(['administrator', 'editor'])
        ->and(Permissions::owner($this->acme)->roles()->pluck('name')->all())->toBe(['administrator', 'editor'])
        ->and(Permissions::owner($this->globex)->roles())->toBeEmpty()
        ->and(Permissions::roles()->pluck('name')->all())->toBe(['administrator', 'editor'])
        ->and(Role::query()->count())->toBe(4);
});

it('enforces one role name per owner in the schema', function (): void {
    Permissions::owner($this->acme)->role('editor');

    expect(fn () => Role::query()->create([
        'name' => 'editor',
        'owner_type' => $this->acme->getMorphClass(),
        'owner_id' => $this->acme->getKey(),
        'owner_key' => $this->acme->getMorphClass().':'.$this->acme->getKey(),
    ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Role::query()->create(['name' => 'admin', 'owner_key' => '']) && Role::query()->create(['name' => 'admin', 'owner_key' => '']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('never serializes the owner class name', function (): void {
    $role = Permissions::owner($this->acme)->role('editor');

    expect(json_encode(Role::query()->findOrFail($role->getKey())))->not->toContain('Tenant')
        ->and(Role::query()->findOrFail($role->getKey())->toArray())->not->toHaveKeys(['owner_type', 'owner_key'])
        ->and(Role::query()->findOrFail($role->getKey())->toArray())->toHaveKey('owner_id');
});

it('refuses an unsaved owner', function (): void {
    expect(fn () => Permissions::owner(new Tenant)->role('editor'))
        ->toThrow(PermissionException::class, 'unsaved');
});
