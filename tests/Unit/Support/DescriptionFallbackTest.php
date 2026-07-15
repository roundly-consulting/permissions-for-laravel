<?php

declare(strict_types=1);

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

it('falls back to the non-disclosing Fallback mode for an unrecognized value', function (): void {
    config()->set('permissions.description_fallback', 'nonsense');

    expect(DescriptionFallback::fromConfig())->toBe(FallbackMode::Fallback);
});
