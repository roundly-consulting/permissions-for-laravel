<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;

/**
 * Scope-list, window and fingerprint rules shared by the handle, the actions and the Gate.
 *
 * @internal
 */
final class Scopes
{
    /** Unit separator between fingerprint fields; never part of a key, a class or a date. */
    private const string SEPARATOR = "\x1F";

    /** Marks an absent (null) field, distinct from every real value. */
    private const string NONE = "\x00";

    /**
     * A scope model or an iterable of them, as a non-empty list of saved models.
     *
     * @return list<Model>
     */
    public static function models(mixed $scopes): array
    {
        $models = [];

        foreach (is_iterable($scopes) ? $scopes : [$scopes] as $scope) {
            if (! $scope instanceof Model) {
                throw PermissionException::notAScope($scope);
            }

            ScopeReference::of($scope); // refuses an unsaved scope
            $models[] = $scope;
        }

        return $models;
    }

    /**
     * @param  list<Model>  $models
     * @return list<ScopeReference>
     */
    public static function references(array $models): array
    {
        return array_map(static fn (Model $model): ScopeReference => ScopeReference::of($model), $models);
    }

    /** The check clock: now, in UTC — `travelTo()` / `Date::setTestNow()` move it. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->utc();
    }

    public static function utc(DateTimeInterface $value): CarbonImmutable
    {
        return UtcDateTime::normalize($value);
    }

    /** A window must end after it starts. */
    public static function assertWindow(?CarbonImmutable $from, ?CarbonImmutable $until): void
    {
        if ($from !== null && $until !== null && $from->greaterThanOrEqualTo($until)) {
            throw PermissionException::invalidWindow($from, $until);
        }
    }

    /** A write must not land a window that has already ended. */
    public static function assertNotOver(?CarbonImmutable $until): void
    {
        if ($until !== null && $until->lessThanOrEqualTo(self::now())) {
            throw PermissionException::windowAlreadyEnded($until);
        }
    }

    /**
     * The sha256 of an assignment's identity tuple — the unique key every write upserts on.
     * Keys are compared as strings, so an int key and its string form fingerprint the same.
     */
    public static function fingerprint(
        Model $holder,
        int|string $grantId,
        ?ScopeReference $scope,
        ?CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): string {
        $key = $holder->getKey();

        return hash('sha256', implode(self::SEPARATOR, [
            $holder->getMorphClass(),
            is_scalar($key) ? (string) $key : self::NONE,
            (string) $grantId,
            $scope === null ? self::NONE : $scope->type,
            $scope === null ? self::NONE : $scope->id,
            $from === null ? self::NONE : $from->format(UtcDateTime::FORMAT),
            $until === null ? self::NONE : $until->format(UtcDateTime::FORMAT),
        ]));
    }
}
