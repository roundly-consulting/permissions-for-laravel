<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Exceptions;

use RuntimeException;

/**
 * Base exception for every error this package raises, so consumers can catch
 * the whole family with a single `catch (PermissionException $e)`.
 */
class PermissionException extends RuntimeException {}
