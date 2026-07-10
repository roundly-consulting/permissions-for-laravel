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

it('builds a translatable invalid-type exception per noun', function (): void {
    expect(PermissionException::invalidType('Permission')->getMessage())
        ->toBe('Permissions must be strings, backed enums, or Permission models.')
        ->and(PermissionException::invalidType('Role')->getMessage())
        ->toBe('Roles must be strings, backed enums, or Role models.');
});

it('builds a translatable unsaved-model exception per noun', function (): void {
    expect(PermissionException::unsavedModel('Permission')->getMessage())
        ->toBe('Cannot grant an unsaved permission model; persist it before granting.')
        ->and(PermissionException::unsavedModel('Role')->getMessage())
        ->toBe('Cannot grant an unsaved role model; persist it before granting.');
});
