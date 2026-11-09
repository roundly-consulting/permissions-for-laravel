<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\Tenant;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permissions::permission('posts.edit');
    Permissions::role('editor')->givePermissionTo('posts.edit');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->project = Project::query()->create(['name' => 'A']);
    $this->tenant = Tenant::query()->create(['name' => 'acme']);
});

afterEach(function (): void {
    Relation::morphMap([], merge: false);
});

it('forgets a scope: its assignments and the roles it owns', function (): void {
    $other = Project::query()->create(['name' => 'B']);
    Permissions::owner($this->project)->role('reviewer')->givePermissionTo('posts.edit');
    Permissions::for($this->user)->in($this->project)->assignRole('editor', 'reviewer');
    Permissions::for($this->user)->in($this->project)->givePermissionTo('posts.edit');
    Permissions::for($this->user)->in($other)->assignRole('editor');

    expect(Permissions::forgetScope($this->project))->toBe(4)
        ->and(Permissions::for($this->user)->in($this->project)->hasRole(['editor', 'reviewer']))->toBeFalse()
        ->and(Permissions::for($this->user)->in($other)->hasRole('editor'))->toBeTrue()
        ->and(Role::query()->pluck('name')->all())->toBe(['editor'])
        ->and(DB::table('permission_role')->count())->toBe(1);
});

it('prunes assignments of dead holders and dead scopes, and roles of dead owners', function (): void {
    $gone = User::query()->create(['name' => 'gone']);
    $deadProject = Project::query()->create(['name' => 'dead']);
    $deadTenant = Tenant::query()->create(['name' => 'dead']);

    Permissions::for($gone)->in($this->project)->assignRole('editor');
    Permissions::for($this->user)->in($deadProject)->givePermissionTo('posts.edit');
    Permissions::for($this->user)->in($this->project)->assignRole('editor');            // survives
    Permissions::for($this->user)->until(now()->addDay())->assignRole('editor');       // survives (unscoped)
    Permissions::owner($deadTenant)->role('reviewer');
    Permissions::owner($this->tenant)->role('reviewer');                               // survives

    $gone->delete();
    $deadProject->delete();
    $deadTenant->delete();

    expect(Permissions::pruneOrphans())->toBe(3)
        ->and(DB::table('role_assignments')->count())->toBe(2)
        ->and(DB::table('permission_assignments')->count())->toBe(0)
        ->and(Role::query()->where('name', 'reviewer')->count())->toBe(1);
});

it('prunes assignments whose scope type no longer resolves', function (): void {
    Permissions::for($this->user)->in($this->project)->assignRole('editor');
    DB::table('role_assignments')->update(['scope_type' => 'App\\Models\\Gone']);

    expect(Permissions::pruneOrphans())->toBe(1);
});

it('reads through a morph map', function (): void {
    Relation::morphMap(['project' => Project::class, 'user' => User::class]);

    Permissions::for($this->user)->in($this->project)->assignRole('editor');

    expect(DB::table('role_assignments')->value('scope_type'))->toBe('project')
        ->and(DB::table('role_assignments')->value('model_type'))->toBe('user')
        ->and(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeTrue()
        ->and(Permissions::pruneOrphans())->toBe(0);
});

it('serves a long-lived instance from the memo until a boundary flushes it', function (string $boundary): void {
    $loaded = User::query()->findOrFail($this->user->getKey());
    expect(Permissions::for($loaded)->in($this->project)->hasRole('editor'))->toBeFalse();

    // Another process grants (a bulk write the memo cannot see).
    DB::table('role_assignments')->insert([
        'role_id' => Permissions::findRole('editor')?->getKey(),
        'model_type' => $loaded->getMorphClass(),
        'model_id' => $loaded->getKey(),
        'scope_type' => $this->project->getMorphClass(),
        'scope_id' => $this->project->getKey(),
        'fingerprint' => str_repeat('a', 64),
    ]);

    expect(Permissions::for($loaded)->in($this->project)->hasRole('editor'))->toBeFalse();

    match ($boundary) {
        'job' => event(new JobProcessing('sync', tap(Mockery::mock(Job::class), static function (mixed $job): void {
            $job->allows('payload')->andReturn([]);
        }))),
        'flushMemo' => Permissions::cache()->flushMemo(),
    };

    expect(Permissions::for($loaded)->in($this->project)->hasRole('editor'))->toBeTrue();
})->with(['job', 'flushMemo']);

it('drops the memo when a transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            Permissions::for($this->user)->in($this->project)->assignRole('editor');
            expect(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeTrue();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeFalse()
        ->and(DB::table('role_assignments')->count())->toBe(0);
});

it('flushes the memo at every Octane boundary', function (string $event): void {
    $resolver = app(GrantResolver::class);
    $resolver->rows($this->user);

    $memo = (new ReflectionProperty($resolver, 'memo'))->getValue($resolver);
    expect($memo)->not->toBeEmpty();

    event($event, [new stdClass]);

    expect((new ReflectionProperty($resolver, 'memo'))->getValue($resolver))->toBe([]);
})->with([
    'Laravel\Octane\Events\RequestReceived',
    'Laravel\Octane\Events\TaskReceived',
    'Laravel\Octane\Events\TickReceived',
]);
