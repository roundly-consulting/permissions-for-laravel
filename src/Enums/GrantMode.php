<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a permission grant reconciles with the grants a model already holds.
 *
 * The distinction is the load-bearing invariant behind the additive registry:
 * `Additive` never strips grants another caller registered, while
 * `Authoritative` makes the given set the complete set.
 */
enum GrantMode: string
{
    use Helpers;

    /** Attach the given permissions without detaching any existing grants. */
    case Additive = 'additive';

    /** Make the given permissions the exact, complete set (detaches the rest). */
    case Authoritative = 'authoritative';

    /** Whether this mode is allowed to detach grants that are not in the given set. */
    public function detaches(): bool
    {
        return $this === self::Authoritative;
    }
}
