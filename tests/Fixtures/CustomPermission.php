<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Permissions\Models\Permission;

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
    public static int $created = 0;

    protected static function booted(): void
    {
        self::created(static function (): void {
            self::$created++;
        });
    }
}
