<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Exceptions;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Base exception for every error this package raises, so consumers can catch
 * the whole family with a single `catch (PermissionException $e)`.
 */
class PermissionException extends RuntimeException
{
    /**
     * A grant argument was neither a string, a backed enum, nor the expected model.
     *
     * @param  string  $what  The singular studly noun ("Permission" or "Role").
     */
    public static function invalidType(string $what): self
    {
        return new self(__(':plural must be strings, backed enums, or :singular models.', [
            'plural' => Str::plural($what),
            'singular' => $what,
        ]));
    }

    /**
     * A grant argument was a model instance that has not been persisted yet, so
     * its key is null and would poison the pivot.
     *
     * @param  string  $what  The singular studly noun ("Permission" or "Role").
     */
    public static function unsavedModel(string $what): self
    {
        return new self(__('Cannot grant an unsaved :singular model; persist it before granting.', [
            'singular' => Str::lower($what),
        ]));
    }

    /**
     * A grant was scoped to a model that cannot hold what was asked of it — roles on a
     * model without `HasRoles` (a `Role` itself, say), or permissions on a model without
     * `HasPermissions`.
     *
     * @param  string  $what  The plural noun ("roles" or "permissions").
     * @param  string  $trait  The short name of the trait the model is missing.
     */
    public static function cannotHold(Model $holder, string $what, string $trait): self
    {
        return new self(__(':model cannot hold :what; add the :trait trait.', [
            'model' => $holder::class,
            'what' => $what,
            'trait' => $trait,
        ]));
    }

    /**
     * A grant was scoped to a holder that has not been persisted yet, so its key is null
     * and would poison the pivot.
     */
    public static function unsavedHolder(Model $holder): self
    {
        return new self(__('Cannot change the grants of an unsaved :model; persist it first.', [
            'model' => $holder::class,
        ]));
    }

    /**
     * A catalog sync was pointed at something that is not a backed enum.
     */
    public static function notABackedEnum(string $class): self
    {
        return new self(__(':class is not a backed enum; only backed enum cases carry a name.', [
            'class' => $class,
        ]));
    }

    /**
     * A scoped or time-boxed call while `permissions.assignments.enabled` is off — the 2.1
     * tables may not exist yet, so nothing is read or written.
     */
    public static function assignmentsDisabled(): self
    {
        return new self(__('Scoped and time-boxed assignments are off; publish and run the permissions migrations, then set permissions.assignments.enabled.'));
    }

    /** A window that does not end after it starts. */
    public static function invalidWindow(DateTimeInterface $from, DateTimeInterface $until): self
    {
        return new self(__('An assignment window must end after it starts; :from is not before :until.', [
            'from' => $from->format(DateTimeInterface::ATOM),
            'until' => $until->format(DateTimeInterface::ATOM),
        ]));
    }

    /** A write whose window already ended. */
    public static function windowAlreadyEnded(DateTimeInterface $until): self
    {
        return new self(__('An assignment window that ended at :until is already over; it would grant nothing.', [
            'until' => $until->format(DateTimeInterface::ATOM),
        ]));
    }

    /** A write needs exactly one scope (checks take any number). */
    public static function oneScopeToWrite(int $count): self
    {
        return new self(__('A scoped write needs exactly one scope, :count given; only checks accept several.', [
            'count' => $count,
        ]));
    }

    /** `in()` / `scope()` got an empty list — never read as "unscoped". */
    public static function emptyScope(): self
    {
        return new self(__('A scope list must not be empty; pass null for an unscoped call.'));
    }

    /** A scope that is not an Eloquent model. */
    public static function notAScope(mixed $value): self
    {
        return new self(__('A scope must be an Eloquent model, :type given.', ['type' => get_debug_type($value)]));
    }

    /** A scope (or role owner) without a key. */
    public static function unsavedScope(Model $scope): self
    {
        return new self(__('Cannot use an unsaved :model as a scope or role owner; persist it first.', [
            'model' => $scope::class,
        ]));
    }

    /** An owned role used outside its owner's scope. */
    public static function roleOutsideOwner(string $role): self
    {
        return new self(__('The role ":role" belongs to another owner and cannot be assigned in this scope.', [
            'role' => $role,
        ]));
    }

    /** A middleware scope parameter that is missing from the route or not a bound model. */
    public static function missingScopeParameter(string $parameter): self
    {
        return new self(__('The route parameter ":parameter" is missing or is not a bound model, so the permission scope is unknown.', [
            'parameter' => $parameter,
        ]));
    }

    /** A stored assignment date the cast cannot read. */
    public static function invalidStoredDate(string $column): self
    {
        return new self(__('The assignment column ":column" holds a value that is not a date.', ['column' => $column]));
    }
}
