<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;

/**
 * The host's two optional callbacks, kept on a container singleton (not on the manager) so a
 * `Permissions::fake()` swap keeps what the host registered at boot.
 *
 * @internal Use `Permissions::resolveScopeUsing()` / `Permissions::resolveRoleOwnerUsing()`.
 */
final class ScopeResolvers
{
    /** @var (Closure(Authorizable, string): mixed)|null */
    private ?Closure $scope = null;

    /** @var (Closure(Model): mixed)|null */
    private ?Closure $roleOwner = null;

    /** @param  (Closure(Authorizable, string): mixed)|null  $resolver */
    public function scopeUsing(?Closure $resolver): void
    {
        $this->scope = $resolver;
    }

    /** @param  (Closure(Model): mixed)|null  $resolver */
    public function roleOwnerUsing(?Closure $resolver): void
    {
        $this->roleOwner = $resolver;
    }

    /**
     * The ambient scope(s) for a scope-less Gate check, or null for an unscoped check.
     *
     * @return list<Model>|null
     */
    public function ambientScopes(Authorizable $user, string $ability): ?array
    {
        if ($this->scope === null) {
            return null;
        }

        $scopes = ($this->scope)($user, $ability);

        if ($scopes === null) {
            return null;
        }

        $models = Scopes::models($scopes);

        return $models === [] ? null : $models;
    }

    /**
     * The extra role owner whose roles may also be used in this scope (besides the scope
     * itself), or null.
     */
    public function roleOwnerOf(Model $scope): ?Model
    {
        if ($this->roleOwner === null) {
            return null;
        }

        $owner = ($this->roleOwner)($scope);

        if ($owner !== null && ! $owner instanceof Model) {
            throw PermissionException::notAScope($owner);
        }

        return $owner;
    }
}
