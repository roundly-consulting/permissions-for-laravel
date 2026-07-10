<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Exceptions;

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
}
