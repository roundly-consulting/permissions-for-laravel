<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Permissions\PermissionsManager;

final class CacheResetCommand extends Command
{
    /** @var string */
    protected $signature = 'permissions:cache-reset';

    /** @var string */
    protected $description = 'Flush the cached permission catalog.';

    public function handle(PermissionsManager $permissions): int
    {
        $permissions->cache()->forget();

        $this->info('Permission cache flushed.');

        return self::SUCCESS;
    }
}
