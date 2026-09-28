<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Permissions\PermissionsManager;

/**
 * Delete morph-pivot rows whose holder model no longer resolves — the CLI face of
 * `Permissions::pruneOrphans()`.
 */
final class PruneOrphansCommand extends Command
{
    /** @var string */
    protected $signature = 'permissions:prune-orphans';

    /** @var string */
    protected $description = 'Delete role/permission pivot rows whose holder model no longer exists.';

    public function handle(PermissionsManager $permissions): int
    {
        $deleted = $permissions->pruneOrphans();

        $this->info("Pruned {$deleted} orphaned authorization row(s).");

        return self::SUCCESS;
    }
}
