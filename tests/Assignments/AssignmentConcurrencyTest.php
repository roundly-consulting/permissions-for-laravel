<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\Tenant;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;
use RoundlyConsulting\Permissions\Tests\Support\Racer;

/**
 * Real-engine races: this process holds a transaction open while a forked second session
 * (its own connection) runs the competing write. The racer re-reads every model on its own
 * connection — a model the parent loaded is bound to the parent's session.
 *
 * Postgres and MySQL only: SQLite serializes writers, so there is no race to observe.
 */
beforeEach(function (): void {
    Permissions::permission('posts.edit');
    Permissions::role('editor')->givePermissionTo('posts.edit');
    Permissions::role('viewer');
    Permissions::role('reviewer');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->x = Project::query()->create(['name' => 'X']);
    $this->y = Project::query()->create(['name' => 'Y']);
});

$racerUnavailable = fn (): bool => ! Racer::available();

it('leaves one row when the same assignment is written concurrently', function (): void {
    [$userId, $projectId] = [$this->user->getKey(), $this->x->getKey()];

    DB::beginTransaction();
    Permissions::for($this->user)->in($this->x)->assignRole('editor');

    $racer = (new Racer)->run(static function () use ($userId, $projectId): string {
        Permissions::for(User::query()->findOrFail($userId))->in(Project::query()->findOrFail($projectId))->assignRole('editor');

        return 'ok';
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe('ok')
        ->and(DB::table('role_assignments')->count())->toBe(1);
})->skip($racerUnavailable, 'needs a real engine and pcntl');

it('serializes two syncs of one scope, so exactly one caller\'s set wins', function (): void {
    [$userId, $projectId] = [$this->user->getKey(), $this->x->getKey()];
    Permissions::for($this->user)->in($this->x)->assignRole('reviewer');

    DB::beginTransaction();
    Permissions::for($this->user)->in($this->x)->syncRoles(['editor']);

    $racer = (new Racer)->run(static function () use ($userId, $projectId): string {
        Permissions::for(User::query()->findOrFail($userId))->in(Project::query()->findOrFail($projectId))->syncRoles(['viewer']);

        return 'ok';
    })->waitUntilBlockedOrDone();

    // The racer is parked on the holder row lock, not finished.
    clearstatcache();
    expect(DB::table('role_assignments')->pluck('role_id')->count())->toBe(1);

    DB::commit();

    expect($racer->outcome())->toBe('ok');

    Permissions::cache()->flushMemo();
    config()->set('permissions.assignments.global_applies_in_scope', false);

    expect(Permissions::for($this->user)->in($this->x)->roleNames()->all())->toBe(['viewer'])
        ->and(DB::table('role_assignments')->count())->toBe(1);
})->skip($racerUnavailable, 'needs a real engine and pcntl');

it('times out a second sync while the first holds the holder lock', function (): void {
    [$userId, $projectId] = [$this->user->getKey(), $this->x->getKey()];

    DB::beginTransaction();
    Permissions::for($this->user)->in($this->x)->syncRoles(['editor']);

    $racer = (new Racer)->run(static function () use ($userId, $projectId): string {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '300ms'" : 'set innodb_lock_wait_timeout = 1');

        try {
            Permissions::for(User::query()->findOrFail($userId))->in(Project::query()->findOrFail($projectId))->syncRoles(['viewer']);
        } catch (QueryException) {
            return 'blocked';
        }

        return 'not blocked';
    });

    $outcome = $racer->outcome();
    DB::commit();

    expect($outcome)->toBe('blocked');
})->skip($racerUnavailable, 'needs a real engine and pcntl');

it('keeps an additive write in scope X made while scope Y is being synced', function (): void {
    [$userId, $projectId] = [$this->user->getKey(), $this->x->getKey()];

    DB::beginTransaction();
    Permissions::for($this->user)->in($this->y)->syncRoles(['viewer']);

    // Postgres lets the insert through at once; MySQL's next-key locks from the sync's delete
    // park it until the commit. Either way the sync of Y must not take X's row with it.
    $racer = (new Racer)->run(static function () use ($userId, $projectId): string {
        Permissions::for(User::query()->findOrFail($userId))->in(Project::query()->findOrFail($projectId))->assignRole('editor');

        return 'ok';
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe('ok');

    Permissions::cache()->flushMemo();

    expect(Permissions::for($this->user)->in($this->x)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::for($this->user)->in($this->y)->hasRole('viewer'))->toBeTrue();
})->skip($racerUnavailable, 'needs a real engine and pcntl');

it('creates an owned role once when two sessions race for it', function (): void {
    $tenant = Tenant::query()->create(['name' => 'acme']);
    $tenantId = $tenant->getKey();

    DB::beginTransaction();
    $mine = Permissions::owner($tenant)->role('reviewer');

    $racer = (new Racer)->run(static function () use ($tenantId): string {
        return (string) Permissions::owner(Tenant::query()->findOrFail($tenantId))->role('reviewer')->getKey();
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe((string) $mine->getKey())
        ->and(Role::query()->where('name', 'reviewer')->count())->toBe(2); // the global one + the owned one
})->skip($racerUnavailable, 'needs a real engine and pcntl');
