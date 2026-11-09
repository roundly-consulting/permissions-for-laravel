<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Commands;

use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use RoundlyConsulting\Permissions\PermissionsManager;
use RoundlyConsulting\Permissions\Support\Scopes;
use Throwable;

/**
 * Delete assignment rows whose window has ended — the CLI face of
 * `Permissions::pruneExpired()`. Table hygiene only: an ended window grants nothing either way.
 *
 *     php artisan permissions:prune-expired                 # everything that has ended
 *     php artisan permissions:prune-expired --after="30 days" # keep 30 days of history
 */
final class PruneExpiredCommand extends Command
{
    /** @var string */
    protected $signature = 'permissions:prune-expired
        {--after= : Keep rows that ended less than this long ago, e.g. "30 days"}';

    /** @var string */
    protected $description = 'Delete scoped/time-boxed assignment rows whose window has ended.';

    public function handle(PermissionsManager $permissions): int
    {
        $after = $this->option('after');
        $cutoff = Scopes::now();

        if (is_string($after) && trim($after) !== '') {
            try {
                $interval = CarbonInterval::make(trim($after));
            } catch (Throwable) {
                $interval = null;
            }

            if (! $interval instanceof CarbonInterval || $interval->totalSeconds <= 0) {
                $this->error("Cannot read --after=\"{$after}\" as a positive duration such as \"30 days\".");

                return self::INVALID;
            }

            $cutoff = $cutoff->sub($interval);
        }

        $deleted = $permissions->pruneExpired($cutoff);

        $this->info("Pruned {$deleted} expired assignment row(s).");

        return self::SUCCESS;
    }
}
