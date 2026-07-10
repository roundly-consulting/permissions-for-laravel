<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Tests\Fixtures\Post;
use RoundlyConsulting\Permissions\Tests\Fixtures\PostPolicy;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('owner_id')->nullable();
    });

    // A permission whose name collides with a policy ability.
    Permission::findOrCreate('update');
    Gate::policy(Post::class, PostPolicy::class);
});

afterEach(function (): void {
    Schema::dropIfExists('posts');
});

it('defers to the policy when the ability check carries a model (deny case)', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('update'); // coarse permission granted

    $foreignPost = Post::create(['owner_id' => 999]); // not owned by the user

    // Even though the user holds the `update` permission, the model-scoped
    // policy owns this decision and denies it.
    expect(Gate::forUser($user)->allows('update', $foreignPost))->toBeFalse();
});

it('lets the policy allow when its own rule passes', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('update');

    $ownPost = Post::create(['owner_id' => $user->getKey()]);

    expect(Gate::forUser($user)->allows('update', $ownPost))->toBeTrue();
});

it('denies the policy-covered ability for an ungranted user without the permission short-circuiting', function (): void {
    $user = User::create(['name' => 'Ada']); // no permission granted

    $foreignPost = Post::create(['owner_id' => 999]);

    expect(Gate::forUser($user)->allows('update', $foreignPost))->toBeFalse();
});

it('still grants argument-less permission checks it owns', function (): void {
    $user = User::create(['name' => 'Ada']);
    $user->givePermissionTo('update');

    // No model argument — this is the coarse permission check the hook owns.
    expect(Gate::forUser($user)->allows('update'))->toBeTrue();
});
