<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Where and when a scoped / time-boxed write applies: one scope model (null = unscoped) and an
 * optional UTC window — `valid_from` inclusive, `valid_until` exclusive.
 */
final readonly class AssignmentContext
{
    public function __construct(
        public ?Model $scope = null,
        public ?CarbonImmutable $validFrom = null,
        public ?CarbonImmutable $validUntil = null,
    ) {}
}
