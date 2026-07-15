<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Concerns;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Support\DescriptionFallback;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

/**
 * Makes `description` a real translatable attribute on the roles and permissions
 * models: stored as a per-locale JSON map, read back as a string for the current
 * locale, written from either a string (current locale) or a per-locale array.
 *
 * The fallback mode is driven by `permissions.description_fallback` rather than
 * translatable's own global default, and is set per instance (config is only bound
 * at runtime). `initialize{Trait}` is Eloquent's per-model hook, so every instance —
 * including a host subclass — picks up the configured mode.
 *
 * @phpstan-require-extends Model
 */
trait TranslatesDescription
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['description'];

    protected ?FallbackMode $translatableFallbackMode = null;

    public function initializeTranslatesDescription(): void
    {
        $this->translatableFallbackMode = DescriptionFallback::fromConfig();
    }
}
