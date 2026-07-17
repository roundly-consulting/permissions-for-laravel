<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass of the packaged permission, as `permissions.models.permission`
 * invites.
 *
 * It counts its own `created` events so a test can prove the package creates
 * rows *as this class* — Eloquent keys model events by the concrete class, and the
 * provider hangs the catalog-cache invalidation on exactly those events.
 */
final class CustomPermission extends Permission
{
    // The shared trait replaces this fixture's hand-rolled `$created` counter. It is
    // REQUIRED by `toHonourModelSwap`, not detected: omitting it used to drop the
    // created-event half in silence, so a caller who had never thought about the trait got
    // a weaker proof under the same name.
    use CountsCreations;
}
