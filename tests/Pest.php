<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Tests\Configured\ConfiguredTestCase;
use RoundlyConsulting\Permissions\Tests\GateDisabledTestCase;
use RoundlyConsulting\Permissions\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Concurrency', 'Migrations', 'Provider', 'ArchTest.php');
uses(ConfiguredTestCase::class)->in('Configured');
uses(GateDisabledTestCase::class)->in('Toggle');
