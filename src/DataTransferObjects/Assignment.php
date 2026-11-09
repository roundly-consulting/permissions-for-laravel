<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;

/**
 * One role or direct permission a holder has — `Permissions::for($user)->assignments()`.
 *
 * `scope` is the scope model (null = unscoped); `validFrom` (inclusive) and `validUntil`
 * (exclusive) are UTC, null = open-ended. `isActive` is evaluated at the handle's check time
 * (`at()`, default now). A 2.0 grant (`model_roles` / `model_permissions`) reads as unscoped
 * and permanent.
 */
final readonly class Assignment
{
    public function __construct(
        public AssignmentKind $kind,
        public string $name,
        public ?Model $scope,
        public ?CarbonImmutable $validFrom,
        public ?CarbonImmutable $validUntil,
        public bool $isActive,
    ) {}
}
