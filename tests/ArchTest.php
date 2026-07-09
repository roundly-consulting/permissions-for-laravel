<?php

declare(strict_types=1);

// Guard against any third-party runtime vendor sneaking into src/. acme is the
// package we replace outright, so it must never appear; the other roots are common
// non-whitelisted vendors the dependency policy bans from `require`.
arch('src never references a disallowed runtime vendor')
    ->expect('RoundlyConsulting\Permissions')
    ->not->toUse([
        'Acme',
        'Doctrine',
        'GuzzleHttp',
        'Ramsey',
        'Nette',
        'Webmozart',
    ]);

arch('every source file declares strict types')
    ->expect('RoundlyConsulting\Permissions')
    ->toUseStrictTypes();

arch('exceptions live in an Exceptions namespace')
    ->expect('RoundlyConsulting\Permissions\Exceptions')
    ->toExtend('RuntimeException');
