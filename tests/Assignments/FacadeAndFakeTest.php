<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Permissions\DataTransferObjects\Assignment;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\RoleAssignment;
use RoundlyConsulting\Permissions\OwnedRoles;
use RoundlyConsulting\Permissions\PermissionScope;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\Tenant;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));

    Permissions::permission('posts.edit');
    Permissions::permission('reports.export');
    Permissions::role('editor')->givePermissionTo('posts.edit');
    Permissions::role('viewer');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->project = Project::query()->create(['name' => 'A']);
    $this->other = Project::query()->create(['name' => 'B']);
    $this->tenant = Tenant::query()->create(['name' => 'acme']);
});

function failsFakeAssertion(Closure $assertion): void
{
    expect($assertion)->toThrow(AssertionFailedError::class);
}

it('pins the facade contract with the 2.1 surface', function (): void {
    expect(Permissions::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the same code through the facade and an injected manager', function (): void {
    $manager = app(PermissionsManager::class);

    $manager->for($this->user)->in($this->project)->assignRole('editor');

    expect(Permissions::owner($this->tenant))->toBeInstanceOf(OwnedRoles::class)
        ->and(Permissions::scope($this->project))->toBeInstanceOf(PermissionScope::class)
        ->and(Permissions::scope($this->project)->models)->toHaveCount(1)
        ->and(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeTrue();
});

it('lists every assignment, with its scope model and window, and no class names', function (): void {
    $until = now()->addDay();
    $this->user->assignRole('viewer');
    Permissions::for($this->user)->in($this->project)->until($until)->assignRole('editor');
    Permissions::for($this->user)->in($this->other)->givePermissionTo('reports.export');
    Permissions::for($this->user)->from(now()->addHour())->givePermissionTo('posts.edit');

    $all = Permissions::for($this->user)->assignments();

    expect($all)->toHaveCount(4)
        ->and($all->every(static fn (mixed $entry): bool => $entry instanceof Assignment))->toBeTrue();

    $editor = $all->first(static fn (Assignment $entry): bool => $entry->name === 'editor');
    $pending = $all->first(static fn (Assignment $entry): bool => $entry->name === 'posts.edit');
    $legacy = $all->first(static fn (Assignment $entry): bool => $entry->name === 'viewer');

    expect($editor->kind)->toBe(AssignmentKind::Role)
        ->and($editor->scope?->is($this->project))->toBeTrue()
        ->and($editor->validUntil?->equalTo($until))->toBeTrue()
        ->and($editor->isActive)->toBeTrue()
        ->and($pending->kind)->toBe(AssignmentKind::Permission)
        ->and($pending->scope)->toBeNull()
        ->and($pending->isActive)->toBeFalse()
        ->and($legacy->scope)->toBeNull()
        ->and($legacy->validUntil)->toBeNull()
        ->and($legacy->isActive)->toBeTrue();

    $scoped = Permissions::for($this->user)->in($this->project)->assignments();
    expect($scoped->pluck('name')->all())->toBe(['editor'])
        ->and(Permissions::for($this->user)->in($this->project)->at($until)->assignments()->first()?->isActive)->toBeFalse();

    expect(json_encode($all->all()))->not->toContain('Fixtures')
        ->and(json_encode(RoleAssignment::query()->get()))
        ->not->toContain('Fixtures')
        ->not->toContain('fingerprint');
});

it('records scoped and time-boxed writes with their scope and window', function (): void {
    $fake = Permissions::fake();
    $until = now()->addDay();

    Permissions::for($this->user)->in($this->project)->until($until)->assignRole('editor');
    $this->user->assignRole('viewer');
    Permissions::for($this->user)->in($this->project)->from(now()->addHour())->givePermissionTo('reports.export');

    $fake->assertRoleAssigned($this->user, 'editor');
    $fake->assertRoleAssigned($this->user, 'editor', scope: $this->project);
    $fake->assertRoleAssigned($this->user, 'editor', scope: $this->project, until: $until);
    $fake->assertRoleAssigned($this->user, 'viewer');
    failsFakeAssertion(fn () => $fake->assertRoleAssigned($this->user, 'editor', scope: $this->other));
    failsFakeAssertion(fn () => $fake->assertRoleAssigned($this->user, 'editor', until: now()->addWeek()));
    failsFakeAssertion(fn () => $fake->assertRoleAssigned($this->user, 'viewer', scope: $this->project));

    $fake->assertPermissionGranted($this->user, 'reports.export', scope: $this->project, from: now()->addHour());
    failsFakeAssertion(fn () => $fake->assertPermissionGranted($this->user, 'reports.export', from: now()));
    failsFakeAssertion(fn () => $fake->assertPermissionGranted($this->user, 'reports.export', until: now()));
});

it('records scoped syncs, removals, revocations and forgets', function (): void {
    $fake = Permissions::fake();

    Permissions::for($this->user)->in($this->project)->syncRoles(['editor']);
    Permissions::for($this->user)->in($this->project)->removeRole('editor');
    Permissions::for($this->user)->in($this->project)->syncPermissions(['reports.export']);
    Permissions::for($this->user)->in($this->project)->revokePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->project)->forgetAllAuthorization();

    $fake->assertRolesSynced($this->user, ['editor'], scope: $this->project);
    failsFakeAssertion(fn () => $fake->assertRolesSynced($this->user, ['editor'], scope: $this->other));
    $fake->assertRoleRemoved($this->user, 'editor', scope: $this->project);
    failsFakeAssertion(fn () => $fake->assertRoleRemoved($this->user, 'editor', scope: $this->other));
    $fake->assertPermissionsSynced($this->user, ['reports.export'], scope: $this->project);
    failsFakeAssertion(fn () => $fake->assertPermissionsSynced($this->user, ['reports.export'], scope: $this->other));
    $fake->assertPermissionRevoked($this->user, 'reports.export', scope: $this->project);
    failsFakeAssertion(fn () => $fake->assertPermissionRevoked($this->user, 'reports.export', scope: $this->other));
    $fake->assertAuthorizationForgotten($this->user, scope: $this->project);
    failsFakeAssertion(fn () => $fake->assertAuthorizationForgotten($this->user, scope: $this->other));
});

it('records trait sugar and owned-role registration', function (): void {
    $fake = Permissions::fake();

    expect($this->user->hasRoleIn($this->project, 'editor'))->toBeFalse();

    Permissions::owner($this->tenant)->role('reviewer');
    Permissions::owner($this->tenant)->syncRolesFrom(RoleName::class);

    $fake->assertRoleRegistered('reviewer', owner: $this->tenant);
    $fake->assertRoleRegistered('reviewer');
    failsFakeAssertion(fn () => $fake->assertRoleRegistered('reviewer', owner: Tenant::query()->create()));
    $fake->assertSyncedFrom(RoleName::class);
    $fake->assertNoRoleAssigned();
});

it('records forgetScope and pruneExpired', function (): void {
    $fake = Permissions::fake();

    $fake->assertNoScopeForgotten();
    $fake->assertNoExpiredPruned();
    failsFakeAssertion(fn () => $fake->assertScopeForgotten());
    failsFakeAssertion(fn () => $fake->assertExpiredPruned());

    Permissions::forgetScope($this->project);
    $this->artisan('permissions:prune-expired')->assertSuccessful();

    $fake->assertScopeForgotten();
    $fake->assertScopeForgotten($this->project);
    failsFakeAssertion(fn () => $fake->assertScopeForgotten($this->other));
    failsFakeAssertion(fn () => $fake->assertNoScopeForgotten());
    $fake->assertExpiredPruned();
    failsFakeAssertion(fn () => $fake->assertNoExpiredPruned());
});

it('keeps the host resolvers across a fake swap', function (): void {
    Permissions::resolveScopeUsing(fn (): Project => $this->project);
    Permissions::for($this->user)->in($this->project)->assignRole('editor');

    Permissions::fake();

    expect($this->user->can('posts.edit'))->toBeTrue();
});
