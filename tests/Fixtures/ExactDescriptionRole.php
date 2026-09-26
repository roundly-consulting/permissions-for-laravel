<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Tests\Fixtures;

use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Translatable\Enums\FallbackMode;

/**
 * A host subclass using the documented per-model override: its own fallback mode, which must
 * win over `permissions.description_fallback`.
 */
final class ExactDescriptionRole extends Role
{
    protected ?FallbackMode $translatableFallbackMode = FallbackMode::None;
}
