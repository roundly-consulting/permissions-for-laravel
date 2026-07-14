<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * The config contract, pinned in BOTH directions.
 *
 * - Forward: a key the code reads but the package never ships is unreachable — the
 *   host can never set it (shops #30: a whole store-credit feature was dead).
 * - Reverse: a key the package ships but nothing reads is a documented feature that
 *   silently does nothing (alerts #34, media #35).
 *
 * Keys are scraped from real **string tokens**, never the file text — a docblock
 * that merely mentions a key is not a read, and a regex over raw text makes this
 * whole test pass vacuously (media #35 shipped exactly that bug).
 */

/** @return list<string> */
function sourceFiles(): array
{
    $files = [];

    foreach ([__DIR__.'/../../src', __DIR__.'/../../database'] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }
    }

    sort($files);

    return array_values($files);
}

/**
 * Every `permissions.*` config key the source actually reads, taken from string
 * literals in the token stream.
 *
 * @return list<string>
 */
function configKeysReadBySource(): array
{
    $keys = [];

    foreach (sourceFiles() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = trim($token[1], "'\"");

            if (str_starts_with($literal, 'permissions.')) {
                $keys[] = $literal;
            }
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Every leaf key the shipped config file actually defines.
 *
 * @return list<string>
 */
function configKeysShipped(): array
{
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/permissions.php';

    return array_values(array_map(
        static fn (string $key): string => 'permissions.'.$key,
        array_keys(Arr::dot($config)),
    ));
}

it('reads only keys the shipped config file defines', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/permissions.php';

    $read = configKeysReadBySource();

    // Guard the guard: an empty scrape would make every assertion below vacuous.
    expect($read)->not->toBeEmpty();

    foreach ($read as $key) {
        $this->assertTrue(
            Arr::has($config, substr($key, strlen('permissions.'))),
            "src/ reads `config('{$key}')`, but config/permissions.php does not ship it.",
        );
    }
});

it('ships no config key that nothing reads', function (): void {
    $read = configKeysReadBySource();

    foreach (configKeysShipped() as $key) {
        $this->assertContains(
            $key,
            $read,
            "config/permissions.php ships `{$key}`, but no line of src/ ever reads it — a documented feature that does nothing.",
        );
    }
});

it('resolves the configurable models only through the Support resolvers', function (): void {
    // The model seam must never be honoured in some call sites and bypassed in
    // others (certificates #26, media #35) — so only Support/ may name the key.
    foreach (sourceFiles() as $file) {
        if (str_contains($file, '/src/Support/')) {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $this->assertStringStartsNotWith(
                'permissions.models.',
                trim($token[1], "'\""),
                basename($file).' reads a model config key directly — route it through Support\\RoleModel / Support\\PermissionModel.',
            );
        }
    }
});

it('no longer ships the retired migration and key-type keys', function (): void {
    /** @var array<string, mixed> $config */
    $config = require __DIR__.'/../../config/permissions.php';

    // Migrations are publish-only fleet-wide, so there is nothing left to toggle.
    expect(Arr::has($config, 'load_migrations'))->toBeFalse()
        // Renamed to `key_type` (env `PERMISSIONS_KEY_TYPE`) on the toolkit's KeyType.
        ->and(Arr::has($config, 'model_key_type'))->toBeFalse()
        ->and(Arr::has($config, 'key_type'))->toBeTrue();
});
