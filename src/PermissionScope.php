<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions;

use Illuminate\Database\Eloquent\Model;

/**
 * The argument that makes a Gate check scoped — build it with `Permissions::scope($project)`:
 *
 *     $user->can('posts.edit', Permissions::scope($project));
 *     Gate::authorize('posts.edit', Permissions::scope([$team, $parentTeam])); // any of them
 *
 * In Blade, pass it to `can`: `can('posts.edit', Permissions::scope($project))`.
 *
 * The package's Gate hook answers exactly one argument of this type (and only `true` or
 * `null`, never `false`); any other argument still goes to your gates and policies. No policy
 * can bind to this class, so it never collides with one.
 */
final readonly class PermissionScope
{
    /**
     * @param  list<Model>  $models  saved scope models, at least one
     */
    public function __construct(public array $models) {}
}
