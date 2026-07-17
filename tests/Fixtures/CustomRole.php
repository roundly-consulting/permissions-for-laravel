<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass of the packaged role, as `permissions.models.role` invites.
 *
 * It counts its own `created` events so a test can prove the package creates
 * rows *as this class* — Eloquent keys model events by the concrete class, so a
 * package that quietly instantiates its own `Role` never fires them.
 */
final class CustomRole extends Role
{
    // The shared trait replaces this fixture's hand-rolled `$created` counter. It is
    // REQUIRED by `toHonourModelSwap`, not detected: omitting it used to drop the
    // created-event half in silence, so a caller who had never thought about the trait got
    // a weaker proof under the same name.
    use CountsCreations;
}
