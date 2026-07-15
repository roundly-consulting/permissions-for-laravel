<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Tests\Configured\ConfiguredTestCase;
use RoundlyConsulting\Permissions\Tests\GateDisabledTestCase;
use RoundlyConsulting\Permissions\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Concurrency', 'Migrations', 'Provider', 'ArchTest.php');
uses(ConfiguredTestCase::class)->in('Configured');
uses(GateDisabledTestCase::class)->in('Toggle');

/**
 * Whether a real PostgreSQL server is configured for the pgsql lane. Set
 * PERMISSIONS_PGSQL_DATABASE (and the usual PG* env) to run it; the lane skips
 * otherwise so the default SQLite suite stays self-contained.
 */
function pgsqlConfigured(): bool
{
    return env('PERMISSIONS_PGSQL_DATABASE') !== null;
}
