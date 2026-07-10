<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

/**
 * Grants `update` only to the post's owner — the model-scoped decision the
 * Gate::before hook must never override with a coarse same-named permission.
 */
final class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $post->owner_id === $user->getKey();
    }
}
