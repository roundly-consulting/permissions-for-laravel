<?php

declare(strict_types=1);

// Guard against any third-party runtime vendor sneaking into src/. Allow-listing
// only the sanctioned roots (Laravel/Symfony, our own Enums helper, PHP built-ins)
// bans every non-whitelisted vendor implicitly — the dependency policy forbids them
// from `require`, and none is ever named here.
arch('src only uses allowed vendor roots')
    ->expect('RoundlyConsulting\Permissions')
    ->toOnlyUse([
        'RoundlyConsulting\Permissions',
        'RoundlyConsulting\Permissions\Database\Factories',
        'RoundlyConsulting\Enums',
        'Illuminate',
        'Carbon',
        'BackedEnum',
        'RuntimeException',
        // native helpers used unqualified
        'app',
        'config',
        'config_path',
        'database_path',
        'event',
        '__',
    ]);

arch('every source file declares strict types')
    ->expect('RoundlyConsulting\Permissions')
    ->toUseStrictTypes();

arch('exceptions live in an Exceptions namespace')
    ->expect('RoundlyConsulting\Permissions\Exceptions')
    ->toExtend('RuntimeException');
