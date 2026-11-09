<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;

/**
 * A `dateTime` column holding UTC wall time, read back as UTC whatever the app time zone is.
 *
 * Laravel's own `datetime` cast reads a stored `Y-m-d H:i:s` in the app's time zone, so an app
 * on Europe/Bratislava would shift every assignment window by an hour or two. This cast writes
 * the instant converted to UTC (seconds precision) and reads it back as UTC.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface>
 */
final class UtcDateTime implements CastsAttributes
{
    public const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return self::normalize($value);
        }

        if (! is_string($value)) {
            throw PermissionException::invalidStoredDate($key);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat(self::FORMAT, substr($value, 0, 19), 'UTC');
        } catch (InvalidArgumentException) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable) {
            throw PermissionException::invalidStoredDate($key);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::normalize($value)->format(self::FORMAT);
    }

    /** The instant in UTC, truncated to whole seconds — the precision the columns store. */
    public static function normalize(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->utc()->startOfSecond();
    }
}
