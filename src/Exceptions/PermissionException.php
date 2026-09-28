<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Exceptions;

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
}
