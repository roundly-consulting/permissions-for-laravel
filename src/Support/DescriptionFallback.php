<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

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
    public static function fromConfig(): FallbackMode
    {
        $configured = config('permissions.description_fallback');

        if ($configured instanceof FallbackMode) {
            return $configured;
        }

        return FallbackMode::tryFrom((string) $configured) ?? FallbackMode::Fallback;
    }
}
