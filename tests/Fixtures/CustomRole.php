<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Permissions\Models\Role;

/**
 * A host subclass of the packaged role, as `permissions.models.role` invites.
 *
 * It counts its own `created` events so a test can prove the package creates
 * rows *as this class* — Eloquent keys model events by the concrete class, so a
 * package that quietly instantiates its own `Role` never fires them.
 */
final class CustomRole extends Role
{
    public static int $created = 0;

    protected static function booted(): void
    {
        self::created(static function (): void {
            self::$created++;
        });
    }
}
