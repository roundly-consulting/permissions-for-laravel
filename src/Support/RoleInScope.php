<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;

/**
 * The `roleIn($scope, $role)` query scope: holders with one of the roles inside any of the
 * scopes, with the window open at the bound UTC now — plus, while `global_applies_in_scope` is
 * on, the unscoped rows and the 2.0 roles.
 *
 * @internal
 */
final readonly class RoleInScope
{
    /** @var list<ScopeReference> */
    private array $scopes;

    /**
     * @param  Model|iterable<Model>  $scope
     * @param  list<string>  $names
     */
    public function __construct(Model|iterable $scope, private array $names)
    {
        AssignmentRows::assertEnabled();

        $models = Scopes::models($scope);

        if ($models === []) {
            throw PermissionException::emptyScope();
        }

        $this->scopes = Scopes::references($models);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Builder $query, Model $model): Builder
    {
        $assignments = PermissionRegistrar::roleAssignmentsTable();
        $roles = PermissionRegistrar::rolesTable();
        $now = Scopes::now()->format(UtcDateTime::FORMAT);
        $global = PermissionRegistrar::globalAppliesInScope();
        $names = $this->names;
        $scopes = $this->scopes;

        return $query->where(static function (Builder $query) use ($model, $assignments, $roles, $now, $global, $names, $scopes): void {
            $query->whereExists(static function (QueryBuilder $exists) use ($model, $assignments, $roles, $now, $global, $names, $scopes): void {
                $exists->select("{$assignments}.id")
                    ->from($assignments)
                    ->join($roles, "{$roles}.id", '=', "{$assignments}.role_id")
                    ->whereColumn("{$assignments}.model_id", $model->getQualifiedKeyName())
                    ->where("{$assignments}.model_type", $model->getMorphClass())
                    ->whereIn("{$roles}.name", $names)
                    ->where(static function (QueryBuilder $where) use ($assignments, $global, $scopes): void {
                        foreach ($scopes as $scope) {
                            $where->orWhere(static function (QueryBuilder $one) use ($assignments, $scope): void {
                                $one->where("{$assignments}.scope_type", $scope->type)
                                    ->where("{$assignments}.scope_id", $scope->id);
                            });
                        }

                        if ($global) {
                            $where->orWhereNull("{$assignments}.scope_type");
                        }
                    })
                    ->where(static function (QueryBuilder $from) use ($assignments, $now): void {
                        $from->whereNull("{$assignments}.valid_from")->orWhere("{$assignments}.valid_from", '<=', $now);
                    })
                    ->where(static function (QueryBuilder $until) use ($assignments, $now): void {
                        $until->whereNull("{$assignments}.valid_until")->orWhere("{$assignments}.valid_until", '>', $now);
                    });
            });

            if ($global) {
                $query->orWhereHas('roles', static function (Builder $legacy) use ($names): void {
                    $legacy->whereIn('name', $names)->where('owner_key', '');
                });
            }
        });
    }
}
