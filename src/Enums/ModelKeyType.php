<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Enums;

use Illuminate\Database\Schema\Blueprint;
use RoundlyConsulting\Enums\Helpers;

/**
 * The primary-key type of the models that hold roles and permissions.
 *
 * The junction tables (`model_roles`, `model_permissions`) store the holder's
 * key as `model_id`; its column type must match the holder's key strategy.
 * Because the schema freezes at the first tagged release, this is configurable
 * up front rather than fixed to `bigint`.
 */
enum ModelKeyType: string
{
    use Helpers;

    /** Auto-incrementing `unsignedBigInteger` keys (the Laravel default). */
    case BigInt = 'bigint';

    /** UUID string keys (models using `HasUuids`). */
    case Uuid = 'uuid';

    /** ULID string keys (models using `HasUlids`). */
    case Ulid = 'ulid';

    /**
     * Resolve the configured key type, falling back to bigint for any invalid value.
     */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom($value) ?? self::BigInt
            : self::BigInt;
    }

    /**
     * Add the `model_id` column of the matching type to a junction table.
     */
    public function defineModelId(Blueprint $table): void
    {
        match ($this) {
            self::BigInt => $table->unsignedBigInteger('model_id'),
            self::Uuid => $table->uuid('model_id'),
            self::Ulid => $table->ulid('model_id'),
        };
    }
}
