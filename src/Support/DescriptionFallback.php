<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

/**
 * Resolves the configured `permissions.description_fallback` into a translatable
 * FallbackMode. The default is `Fallback` (current locale -> app fallback locale ->
 * null): the least-surprising, non-disclosing choice, so an untranslated description
 * never surfaces content from an unrelated locale. A host opts into `Any` (first
 * available) or `None` (exact only) via config or a per-model override.
 *
 * @internal Configure `permissions.description_fallback` instead.
 */
final class DescriptionFallback
{
    /**
     * Not set (absent, null or blank — a host's `KEY=`) reads as `Fallback`; a case or
     * its string value is taken as-is; anything else throws rather than silently
     * reading as the default.
     *
     * @throws InvalidConfigurationException
     */
    public static function fromConfig(): FallbackMode
    {
        return Config::enum('permissions.description_fallback', FallbackMode::class, FallbackMode::Fallback);
    }
}
