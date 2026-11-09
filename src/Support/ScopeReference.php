<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;

/**
 * A scope (or role owner) as stored: its morph class and its key as a string, so an `int` key
 * and the `"5"` a driver hands back compare equal.
 *
 * @internal
 */
final readonly class ScopeReference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(Model $scope): self
    {
        $key = $scope->getKey();

        if ($key === null || ! is_scalar($key)) {
            throw PermissionException::unsavedScope($scope);
        }

        return new self($scope->getMorphClass(), (string) $key);
    }

    /** The `roles.owner_key` of a role this model owns: `"<morph class>:<key>"`. */
    public function ownerKey(): string
    {
        return $this->type.':'.$this->id;
    }

    /** @param  list<self>  $scopes */
    public function in(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->type === $this->type && $scope->id === $this->id) {
                return true;
            }
        }

        return false;
    }
}
