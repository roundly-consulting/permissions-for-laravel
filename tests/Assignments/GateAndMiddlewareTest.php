<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Http\Middleware\EnsurePermission;
use RoundlyConsulting\Permissions\PermissionScope;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Permissions::permission('posts.edit');
    Permissions::role('editor')->givePermissionTo('posts.edit');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->a = Project::query()->create(['name' => 'A']);
    $this->b = Project::query()->create(['name' => 'B']);

    Permissions::for($this->user)->in($this->a)->assignRole('editor');
});

it('answers a scoped Gate check through the scope value object', function (): void {
    $this->actingAs($this->user);

    expect($this->user->can('posts.edit', Permissions::scope($this->a)))->toBeTrue()
        ->and($this->user->can('posts.edit', Permissions::scope($this->b)))->toBeFalse()
        ->and($this->user->can('posts.edit', Permissions::scope([$this->b, $this->a])))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('posts.edit'))->toBeFalse()
        ->and(Blade::render("@can('posts.edit', \$scope) yes @else no @endcan", ['scope' => Permissions::scope($this->a)]))->toContain('yes')
        ->and(Blade::render("@can('posts.edit', \$scope) yes @else no @endcan", ['scope' => Permissions::scope($this->b)]))->toContain('no');
});

it('only ever answers true or null, so a gate can still allow', function (): void {
    Gate::define('posts.edit', static fn (User $user, PermissionScope $scope): bool => $scope->models[0]->getKey() === 2);

    expect($this->user->can('posts.edit', Permissions::scope($this->b)))->toBeTrue();
});

it('leaves any other argument shape to the policies', function (): void {
    expect($this->user->can('posts.edit', [Permissions::scope($this->a), $this->a]))->toBeFalse()
        ->and($this->user->can('posts.edit', $this->a))->toBeFalse();
});

it('passes through permissions it does not know', function (): void {
    expect($this->user->can('ghost.ability', Permissions::scope($this->a)))->toBeFalse();
});

it('applies an ambient scope to scope-less Gate checks', function (): void {
    $current = $this->a;
    Permissions::resolveScopeUsing(static function (Authorizable $user, string $ability) use (&$current): mixed {
        return $current;
    });

    expect(Gate::forUser($this->user)->allows('posts.edit'))->toBeTrue();

    $current = $this->b;
    expect(Gate::forUser($this->user)->allows('posts.edit'))->toBeFalse();

    $current = [$this->a, $this->b];
    expect(Gate::forUser($this->user)->allows('posts.edit'))->toBeTrue();

    // Never trait methods: the ambient scope is a Gate concern only.
    expect($this->user->hasPermissionTo('posts.edit'))->toBeFalse();
});

it('grants no scoped grant when the ambient resolver returns nothing', function (mixed $nothing): void {
    Permissions::resolveScopeUsing(static fn (): mixed => $nothing);

    expect(Gate::forUser($this->user)->allows('posts.edit'))->toBeFalse();

    $this->user->givePermissionTo('posts.edit');
    expect(Gate::forUser($this->user->refresh())->allows('posts.edit'))->toBeTrue();
})->with([[null], [[]]]);

it('lets an ambient scope include global grants unless strict', function (): void {
    $other = User::query()->create(['name' => 'bob']);
    $other->givePermissionTo('posts.edit');
    Permissions::resolveScopeUsing(fn (): Project => $this->b);

    expect(Gate::forUser($other)->allows('posts.edit'))->toBeTrue();

    config()->set('permissions.assignments.global_applies_in_scope', false);
    expect(Gate::forUser($other->refresh())->allows('posts.edit'))->toBeFalse();
});

it('guards a route by permission inside the bound scope', function (): void {
    Route::middleware(['web', EnsurePermission::using('posts.edit', scope: 'project')])
        ->get('/projects/{project}/edit', static fn (Project $project): string => 'editing '.$project->name)
        ->middleware('Illuminate\Routing\Middleware\SubstituteBindings');

    $this->actingAs($this->user);

    $this->get("/projects/{$this->a->getKey()}/edit")->assertOk()->assertSee('editing A');
    $this->get("/projects/{$this->b->getKey()}/edit")->assertForbidden();
});

it('guards a route unscoped and builds the middleware string', function (): void {
    expect(EnsurePermission::using('posts.edit'))->toBe(EnsurePermission::class.':posts.edit')
        ->and(EnsurePermission::using('posts.edit', 'project'))->toBe(EnsurePermission::class.':posts.edit,project');

    Route::middleware(EnsurePermission::class.':posts.edit')->get('/admin', static fn (): string => 'admin');

    $this->get('/admin')->assertForbidden(); // guest

    $this->actingAs($this->user);
    $this->get('/admin')->assertForbidden(); // only scoped

    $this->user->givePermissionTo('posts.edit');
    $this->actingAs($this->user->refresh());
    $this->get('/admin')->assertOk();
});

it('throws on a scope parameter the route does not bind', function (): void {
    $this->withoutExceptionHandling();
    Route::middleware(EnsurePermission::using('posts.edit', scope: 'project'))
        ->get('/posts/{post}', static fn (): string => 'post');

    $this->actingAs($this->user);

    expect(fn () => $this->get('/posts/1'))
        ->toThrow(PermissionException::class, 'The route parameter "project" is missing');
});
