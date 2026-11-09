<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Concerns\HasRoles;
use RoundlyConsulting\Permissions\DataTransferObjects\AssignmentContext;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\PermissionAssignment;
use RoundlyConsulting\Permissions\Models\RoleAssignment;

/**
 * Queries and rows over the two assignment tables, shared by the write actions.
 *
 * @internal
 */
final class AssignmentRows
{
    public static function assertEnabled(): void
    {
        if (! PermissionRegistrar::assignmentsEnabled()) {
            throw PermissionException::assignmentsDisabled();
        }
    }

    /** @return Builder<RoleAssignment> */
    public static function roles(Model $holder): Builder
    {
        return RoleAssignment::on($holder->getConnectionName())
            ->where('model_type', $holder->getMorphClass())
            ->where('model_id', $holder->getKey());
    }

    /** @return Builder<PermissionAssignment> */
    public static function permissions(Model $holder): Builder
    {
        return PermissionAssignment::on($holder->getConnectionName())
            ->where('model_type', $holder->getMorphClass())
            ->where('model_id', $holder->getKey());
    }

    /**
     * Narrow to exactly one scope — null narrows to the unscoped rows only.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function inScope(Builder $query, ?ScopeReference $scope): Builder
    {
        if ($scope === null) {
            return $query->whereNull('scope_type')->whereNull('scope_id');
        }

        return $query->where('scope_type', $scope->type)->where('scope_id', $scope->id);
    }

    /**
     * One row per grant, keyed by its fingerprint.
     *
     * @param  list<int|string>  $grantIds
     * @return array<string, array<string, mixed>>
     */
    public static function build(Model $holder, string $column, array $grantIds, AssignmentContext $context): array
    {
        $scope = $context->scope === null ? null : ScopeReference::of($context->scope);
        $rows = [];

        foreach ($grantIds as $id) {
            $fingerprint = Scopes::fingerprint($holder, $id, $scope, $context->validFrom, $context->validUntil);

            $rows[$fingerprint] = [
                $column => $id,
                'model_type' => $holder->getMorphClass(),
                'model_id' => $holder->getKey(),
                'scope_type' => $scope?->type,
                'scope_id' => $scope?->id,
                'valid_from' => self::format($context->validFrom),
                'valid_until' => self::format($context->validUntil),
                'fingerprint' => $fingerprint,
            ];
        }

        return $rows;
    }

    /**
     * Validate a write's context: the window ends after it starts and is not already over.
     */
    public static function assertWritable(AssignmentContext $context): void
    {
        Scopes::assertWindow($context->validFrom, $context->validUntil);
        Scopes::assertNotOver($context->validUntil);
    }

    /**
     * Serialize concurrent authoritative writes for one holder: a row lock on the holder
     * itself, held to the end of the surrounding transaction. On SQLite the lock compiles to
     * nothing — SQLite serializes writers anyway.
     */
    public static function lockHolder(Model $holder): void
    {
        $holder->newQueryWithoutScopes()
            ->whereKey($holder->getKey())
            ->lockForUpdate()
            ->get([$holder->getKeyName()]);
    }

    /**
     * Drop the holder's grant memo — only if the memo was ever built in this process.
     */
    public static function forgetMemo(Model $holder): void
    {
        if (app()->resolved(GrantResolver::class)) {
            app(GrantResolver::class)->forget($holder);
        }
    }

    /** Whether the holder can have assignment rows at all (it uses `HasRoles`). */
    public static function holdsAssignments(Model $holder): bool
    {
        return in_array(HasRoles::class, class_uses_recursive($holder), true);
    }

    private static function format(?CarbonImmutable $value): ?string
    {
        return $value?->format(UtcDateTime::FORMAT);
    }
}
