<?php

declare(strict_types=1);

use RoundlyConsulting\Permissions\Exceptions\PermissionDoesNotExist;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Exceptions\RoleDoesNotExist;

it('builds a permission-does-not-exist exception carrying the name', function (): void {
    $exception = PermissionDoesNotExist::named('auth.users.view');

    expect($exception)->toBeInstanceOf(PermissionException::class)
        ->and($exception->getMessage())->toBe('There is no permission named "auth.users.view".');
});

it('builds a role-does-not-exist exception carrying the name', function (): void {
    $exception = RoleDoesNotExist::named('administrator');

    expect($exception)->toBeInstanceOf(PermissionException::class)
        ->and($exception->getMessage())->toBe('There is no role named "administrator".');
});
