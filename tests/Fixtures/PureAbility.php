<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

/**
 * A pure (unbacked) enum: its cases carry no value, so they can never name a row.
 */
enum PureAbility
{
    case View;
}
