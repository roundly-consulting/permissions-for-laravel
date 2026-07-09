<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Exceptions;

final class PermissionDoesNotExist extends PermissionException
{
    public static function named(string $name): self
    {
        return new self(__('There is no permission named ":name".', ['name' => $name]));
    }
}
