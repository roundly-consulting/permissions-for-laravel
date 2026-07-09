<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Tests\GateDisabledTestCase;
use RoundlyConsulting\Permissions\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'ArchTest.php');
uses(GateDisabledTestCase::class)->in('Toggle');
