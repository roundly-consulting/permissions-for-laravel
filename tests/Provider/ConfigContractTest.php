<?php

declare(strict_types=1);

/**
 * The config contract, pinned in BOTH directions.
 *
 *  - Forward — a key the code reads but the package never ships is unreachable: the host
 *    can never set it. That is shops #18, whose whole store-credit feature read
 *    `shops.payments.*` while the file shipped `payment.*`, and 330 tests stayed green
 *    because the suite set the same wrong key.
 *  - Reverse — a key the package ships but nothing reads is a documented feature that
 *    silently does nothing: media #27's `max_file_size` cap that never applied, alerts
 *    #24's thrice-documented `escalation` key, and this package's own dead
 *    `load_migrations` (since removed).
 *
 * This replaces the hand-rolled tokenizer that did the same job for this package alone.
 * The shared assertion is strictly stronger: it also counts reads through an injected
 * config Repository, follows `sectionVariables` offsets to any depth, and FLAGS an
 * interpolated `config("permissions.{$x}")` rather than silently ignoring it.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/permissions.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database'],
        [
            // Deliberately NO `excludeFromReverse` for the service provider. It renders an
            // `about` section (a render is not a read), but the toolkit's
            // PackageServiceProvider ALSO does its real `bindFromConfig()` reads in the
            // same file — so excluding it would discard the only reader of every bound key
            // and weaken the reverse direction for nothing.
        ],
    );
});
