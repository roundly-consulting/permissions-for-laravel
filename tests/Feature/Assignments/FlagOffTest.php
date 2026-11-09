<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Permissions\Actions\FindOrCreateOwnedRole;
use RoundlyConsulting\Permissions\Actions\ForgetAssignedAuthorization;
use RoundlyConsulting\Permissions\Actions\GrantAssignedPermissions;
use RoundlyConsulting\Permissions\Actions\GrantAssignedRoles;
use RoundlyConsulting\Permissions\Actions\RemoveAssignedRoles;
use RoundlyConsulting\Permissions\Actions\RevokeAssignedPermissions;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\Enums\GrantMode;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\PermissionScope;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

/**
 * With `permissions.assignments.enabled` off (the 2.0 default), every 2.1 entry point refuses
 * before touching the database — the 2.1 tables may not exist yet.
 */
beforeEach(function (): void {
    Permissions::role('editor');
    Permissions::permission('posts.edit');
    $this->user = User::query()->create(['name' => 'ada']);
    $this->project = Project::query()->create(['name' => 'A']);
});

it('defaults to off', function (): void {
    expect(config('permissions.assignments.enabled'))->toBeFalse();
});

it('refuses every scoped or time-boxed call', function (Closure $call): void {
    expect(fn () => $call($this))->toThrow(PermissionException::class, 'Scoped and time-boxed assignments are off');
})->with([
    'in' => [fn ($test) => Permissions::for($test->user)->in($test->project)],
    'until' => [fn ($test) => Permissions::for($test->user)->until(now()->addDay())],
    'from' => [fn ($test) => Permissions::for($test->user)->from(now())],
    'between' => [fn ($test) => Permissions::for($test->user)->between(now(), now()->addDay())],
    'at' => [fn ($test) => Permissions::for($test->user)->at(now())],
    'owner' => [fn ($test) => Permissions::owner($test->project)],
    'scope' => [fn ($test) => Permissions::scope($test->project)],
    'forgetScope' => [fn ($test) => Permissions::forgetScope($test->project)],
    'pruneExpired' => [fn ($test) => Permissions::pruneExpired()],
    'hasRoleIn' => [fn ($test) => $test->user->hasRoleIn($test->project, 'editor')],
    'hasPermissionIn' => [fn ($test) => $test->user->hasPermissionIn($test->project, 'x')],
    'roleIn' => [fn ($test) => User::query()->roleIn($test->project, 'editor')],
    'gate' => [fn ($test) => $test->user->can('posts.edit', new PermissionScope([$test->project]))],
    'grant roles action' => [fn ($test) => app(GrantAssignedRoles::class)->execute($test->user, ['editor'], GrantMode::Additive, new AssignmentContext($test->project))],
    'grant permissions action' => [fn ($test) => app(GrantAssignedPermissions::class)->execute($test->user, [], GrantMode::Additive, new AssignmentContext)],
    'remove roles action' => [fn ($test) => app(RemoveAssignedRoles::class)->execute($test->user, ['editor'], $test->project)],
    'revoke permissions action' => [fn ($test) => app(RevokeAssignedPermissions::class)->execute($test->user, [], $test->project)],
    'forget action' => [fn ($test) => app(ForgetAssignedAuthorization::class)->execute($test->user, $test->project)],
    'owned role action' => [fn ($test) => app(FindOrCreateOwnedRole::class)->execute($test->project, 'editor')],
]);

it('refuses the prune command with a clear error', function (): void {
    expect(fn () => Artisan::call('permissions:prune-expired'))->toThrow(PermissionException::class);
});

it('reads through the handle on 2.0 grants only', function (): void {
    $this->user->assignRole('editor');

    expect(Permissions::for($this->user)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::for($this->user)->roleNames()->all())->toBe(['editor'])
        ->and(Permissions::for($this->user)->assignments()->pluck('name')->all())->toBe(['editor']);
});

it('reports assignments OFF in about', function (): void {
    Artisan::call('about', ['--only' => 'permissions']);

    expect(Artisan::output())->toMatch('/Assignments\s*\.*\s*OFF/');
});
