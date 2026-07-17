<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Tests\Configured\ConfiguredTestCase;
use RoundlyConsulting\Permissions\Tests\GateDisabledTestCase;
use RoundlyConsulting\Permissions\Tests\TestCase;

// Explicit paths: the Configured and Toggle directories need their own base cases, and a
// blanket `->in(__DIR__)` would claim them first. ArchTest.php is listed because
// `swappableModelsAreNotFinal` reads the `permissions.models.*` config defaults and
// `runtimeRequireIsWhitelisted` reads composer.json — both need the app booted, and an
// arch file is not automatically test-cased.
uses(TestCase::class)->in('Unit', 'Feature', 'Concurrency', 'Migrations', 'Provider', 'ArchTest.php');
uses(ConfiguredTestCase::class)->in('Configured');
uses(GateDisabledTestCase::class)->in('Toggle');

// The bespoke `pgsqlConfigured()` helper is gone, with its PERMISSIONS_PGSQL_* convention.
// It asked whether an env var was SET rather than whether an engine was REACHABLE, so the
// lane it gated reported green by skipping whenever the server was absent. The real-engine
// cases now gate on `DriverMatrix::driver()` / `connectionAvailable()` — the connection the
// suite is actually on — and run on the fleet's standard whole-suite TESTING_DB_* leg.
