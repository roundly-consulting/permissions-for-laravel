<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;

/**
 * One assignment row as the grant memo keeps it: never a precomputed yes/no, always the row
 * with its window, so every check is decided against the clock at check time.
 *
 * @internal
 */
final readonly class GrantRow
{
    /**
     * @param  list<string>  $permissions  for a role row: the permissions the role carries
     */
    public function __construct(
        public AssignmentKind $kind,
        public string $name,
        public ?ScopeReference $scope,
        public ?CarbonImmutable $validFrom,
        public ?CarbonImmutable $validUntil,
        public array $permissions = [],
    ) {}

    /** Inclusive start, exclusive end. */
    public function activeAt(CarbonImmutable $at): bool
    {
        return ($this->validFrom === null || $this->validFrom->lessThanOrEqualTo($at))
            && ($this->validUntil === null || $at->lessThan($this->validUntil));
    }

    /**
     * Whether the row counts for a check in these scopes (null = an unscoped check).
     *
     * A scoped row never satisfies an unscoped check. An unscoped row satisfies a scoped check
     * only when `global_applies_in_scope` is on.
     *
     * @param  list<ScopeReference>|null  $scopes
     */
    public function appliesIn(?array $scopes, bool $globalAppliesInScope): bool
    {
        if ($this->scope === null) {
            return $scopes === null || $globalAppliesInScope;
        }

        return $scopes !== null && $this->scope->in($scopes);
    }
}
