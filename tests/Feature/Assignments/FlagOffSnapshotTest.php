<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Tests\Fixtures\PermissionName;
use RoundlyConsulting\Permissions\Tests\Fixtures\RoleName;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The 2.0 contract, byte for byte: with `permissions.assignments.enabled` off (the default),
 * every statement the package sends is the one 2.0.0 sent. The snapshot was recorded on the
 * untouched 2.0.0 source, per driver, and the 2.1 migrations are applied here — so this also
 * proves the new columns and the swapped `roles` unique index change no 2.0 statement.
 *
 * Bindings keep their values except timestamps (they move with the clock).
 */
function runTwoPointZeroScenario(): void
{
    Permissions::syncFrom(PermissionName::class);
    Permissions::syncRolesFrom(RoleName::class);
    Permissions::permission('posts.edit');
    Permissions::role('editor');
    Permissions::role('viewer');

    $editor = Permissions::findRole('editor');
    $editor?->givePermissionTo('posts.edit', PermissionName::ViewUsers);
    $editor?->syncPermissions(['posts.edit']);
    $editor?->revokePermissionTo('posts.edit');
    $editor?->givePermissionTo('posts.edit');

    $user = User::query()->create(['name' => 'ada']);
    $other = User::query()->create(['name' => 'bob']);

    $user->assignRole('editor', RoleName::Administrator);
    Permissions::for($other)->assignRole('viewer');
    $user->givePermissionTo(PermissionName::EditUsers);
    $user->removeRole(RoleName::Administrator);
    $user->syncRoles(['editor', 'viewer']);
    $user->syncPermissions([PermissionName::ViewUsers]);
    $user->revokePermissionTo(PermissionName::ViewUsers);

    $fresh = User::query()->findOrFail($user->getKey());
    $fresh->hasRole('editor');
    $fresh->hasRole(['viewer', 'ghost']);
    $fresh->hasPermissionTo('posts.edit');
    $fresh->hasPermissionTo(PermissionName::EditUsers);
    $fresh->getRoleNames();
    $fresh->getAllPermissions();
    $fresh->getDirectPermissions();

    Gate::forUser(User::query()->findOrFail($user->getKey()))->allows('posts.edit');
    Gate::forUser(User::query()->findOrFail($other->getKey()))->allows('posts.edit');

    User::query()->role('editor')->get();
    User::query()->role([RoleName::Editor, 'viewer'])->count();

    Permissions::roles();
    Permissions::findRole('ghost');
    Permissions::findPermission('posts.edit');
    Permissions::exists('posts.edit');

    $other->forgetAllAuthorization();
    $other->delete();
    Permissions::pruneOrphans();
}

/** @return list<array{sql: string, bindings: list<mixed>}> */
function recordTwoPointZeroScenario(): array
{
    $statements = [];

    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = [
            'sql' => $query->sql,
            'bindings' => array_map(
                static fn (mixed $value): mixed => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) === 1
                    ? '<timestamp>'
                    : $value,
                array_values($query->bindings),
            ),
        ];
    });

    runTwoPointZeroScenario();

    return $statements;
}

it('sends exactly the 2.0 statements while assignments are off', function (): void {
    $driver = DriverMatrix::driver();
    $file = __DIR__."/../../Fixtures/snapshots/flag-off-{$driver}.json";

    $statements = recordTwoPointZeroScenario();

    if (getenv('PERMISSIONS_RECORD_SNAPSHOT') === '1') {
        file_put_contents($file, json_encode($statements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    expect($file)->toBeFile();

    /** @var list<array{sql: string, bindings: list<mixed>}> $snapshot */
    $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    expect($statements)->toHaveCount(count($snapshot))
        ->and($statements)->toBe($snapshot);
})->skip(fn (): bool => ! is_file(__DIR__.'/../../Fixtures/snapshots/flag-off-'.DriverMatrix::driver().'.json')
    && getenv('PERMISSIONS_RECORD_SNAPSHOT') !== '1', 'no 2.0 snapshot recorded for this driver');
