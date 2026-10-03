<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Permissions\Support\DescriptionFallback;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

it('returns a configured FallbackMode instance as-is', function (): void {
    config()->set('permissions.description_fallback', FallbackMode::Any);

    expect(DescriptionFallback::fromConfig())->toBe(FallbackMode::Any);
});

it('parses a plain string config value into a FallbackMode', function (string $value, FallbackMode $expected): void {
    config()->set('permissions.description_fallback', $value);

    expect(DescriptionFallback::fromConfig())->toBe($expected);
})->with([
    ['none', FallbackMode::None],
    ['fallback', FallbackMode::Fallback],
    ['any', FallbackMode::Any],
]);

it('refuses an unrecognized value instead of reading it as Fallback (strict config)', function (mixed $value, string $given): void {
    config()->set('permissions.description_fallback', $value);

    expect(fn (): FallbackMode => DescriptionFallback::fromConfig())
        ->toThrow(InvalidConfigurationException::class, "Configuration value [permissions.description_fallback] must be one of [none, fallback, any], [{$given}] given.");
})->with([
    ['nonsense', 'nonsense'],
    ['Any', 'Any'],
    [1, '1'],
]);

it('reads an unset or blank value as the non-disclosing Fallback mode', function (mixed $unset): void {
    config()->set('permissions.description_fallback', $unset);

    expect(DescriptionFallback::fromConfig())->toBe(FallbackMode::Fallback);
})->with(['null' => [null], 'blank' => [''], 'whitespace' => ['  ']]);

it('hands the raw env string to the strict reader (strict config)', function (): void {
    $_SERVER['PERMISSIONS_DESCRIPTION_FALLBACK'] = 'anyy';

    try {
        /** @var array{description_fallback: mixed} $config */
        $config = require __DIR__.'/../../../config/permissions.php';
    } finally {
        unset($_SERVER['PERMISSIONS_DESCRIPTION_FALLBACK']);
    }

    config()->set('permissions.description_fallback', $config['description_fallback']);

    expect($config['description_fallback'])->toBe('anyy')
        ->and(fn (): FallbackMode => DescriptionFallback::fromConfig())->toThrow(InvalidConfigurationException::class);
});

it('reads a valid env string from the shipped config', function (): void {
    $_SERVER['PERMISSIONS_DESCRIPTION_FALLBACK'] = 'any';

    try {
        /** @var array{description_fallback: mixed} $config */
        $config = require __DIR__.'/../../../config/permissions.php';
    } finally {
        unset($_SERVER['PERMISSIONS_DESCRIPTION_FALLBACK']);
    }

    config()->set('permissions.description_fallback', $config['description_fallback']);

    expect(DescriptionFallback::fromConfig())->toBe(FallbackMode::Any);
});
