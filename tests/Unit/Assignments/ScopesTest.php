<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Permissions\Casts\UtcDateTime;
use RoundlyConsulting\Permissions\Enums\AssignmentKind;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Models\RoleAssignment;
use RoundlyConsulting\Permissions\Support\GrantResolver;
use RoundlyConsulting\Permissions\Support\GrantRow;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;
use RoundlyConsulting\Permissions\Support\ScopeReference;
use RoundlyConsulting\Permissions\Support\Scopes;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;
use RoundlyConsulting\Permissions\Tests\Fixtures\UuidUser;

function holderWithKey(int|string $key): User|UuidUser
{
    return is_int($key) || ctype_digit($key) ? (new User)->forceFill(['id' => $key]) : (new UuidUser)->forceFill(['id' => $key]);
}

it('fingerprints the same identity the same way, whatever the key type', function (): void {
    $scope = new ScopeReference('project', '7');
    $until = CarbonImmutable::parse('2026-11-10 10:00:00.900', 'UTC')->startOfSecond();

    expect(Scopes::fingerprint(holderWithKey(5), 3, $scope, null, $until))
        ->toBe(Scopes::fingerprint(holderWithKey('5'), '3', $scope, null, $until))
        ->toHaveLength(64)
        ->and(Scopes::fingerprint(holderWithKey('01J9ZQ8V7W6X5Y4Z3A2B1C0D9E'), 3, null, null, null))
        ->toBe(Scopes::fingerprint(holderWithKey('01J9ZQ8V7W6X5Y4Z3A2B1C0D9E'), 3, null, null, null))
        ->and(Scopes::fingerprint(holderWithKey('9b2f6c1e-3d4a-4b5c-8d7e-6f5a4b3c2d1e'), 3, $scope, null, null))
        ->not->toBe(Scopes::fingerprint(holderWithKey('9b2f6c1e-3d4a-4b5c-8d7e-6f5a4b3c2d1f'), 3, $scope, null, null));
});

it('tells every field and every null marker apart', function (): void {
    $holder = holderWithKey(1);
    $at = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');
    $prints = [
        Scopes::fingerprint($holder, 1, null, null, null),
        Scopes::fingerprint($holder, 2, null, null, null),
        Scopes::fingerprint(holderWithKey(2), 1, null, null, null),
        Scopes::fingerprint($holder, 1, new ScopeReference('project', '1'), null, null),
        Scopes::fingerprint($holder, 1, new ScopeReference('team', '1'), null, null),
        Scopes::fingerprint($holder, 1, null, $at, null),   // from only
        Scopes::fingerprint($holder, 1, null, null, $at),   // until only — must differ from "from only"
        Scopes::fingerprint($holder, 1, new ScopeReference('', ''), null, null), // empty is not null
    ];

    expect(array_unique($prints))->toHaveCount(count($prints));
});

it('keys a scope by its morph alias when there is one', function (): void {
    Relation::morphMap(['project' => Project::class]);

    try {
        $project = (new Project)->forceFill(['id' => 4]);

        expect(ScopeReference::of($project))->toEqual(new ScopeReference('project', '4'))
            ->and(ScopeReference::of($project)->ownerKey())->toBe('project:4');
    } finally {
        Relation::morphMap([], merge: false);
    }
});

it('refuses an unsaved scope', function (): void {
    expect(fn () => ScopeReference::of(new Project))->toThrow(PermissionException::class, 'unsaved');
});

it('decides activity with an inclusive start and an exclusive end', function (): void {
    $from = CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC');
    $until = CarbonImmutable::parse('2026-11-10 17:00:00', 'UTC');
    $row = new GrantRow(AssignmentKind::Role, 'editor', null, $from, $until);

    expect($row->activeAt($from->subSecond()))->toBeFalse()
        ->and($row->activeAt($from))->toBeTrue()
        ->and($row->activeAt($until->subSecond()))->toBeTrue()
        ->and($row->activeAt($until))->toBeFalse()
        ->and((new GrantRow(AssignmentKind::Role, 'editor', null, null, null))->activeAt($from))->toBeTrue();
});

it('never lets a scoped row answer an unscoped check', function (): void {
    $a = new ScopeReference('project', '1');
    $b = new ScopeReference('project', '2');
    $scoped = new GrantRow(AssignmentKind::Role, 'editor', $a, null, null);
    $global = new GrantRow(AssignmentKind::Role, 'editor', null, null, null);

    expect($scoped->appliesIn(null, true))->toBeFalse()
        ->and($scoped->appliesIn([$b], true))->toBeFalse()
        ->and($scoped->appliesIn([$b, $a], false))->toBeTrue()
        ->and($global->appliesIn(null, false))->toBeTrue()
        ->and($global->appliesIn([$a], true))->toBeTrue()
        ->and($global->appliesIn([$a], false))->toBeFalse();
});

it('normalizes a scope list and refuses junk', function (): void {
    $project = Project::query()->create(['name' => 'A']);

    expect(Scopes::models($project))->toBe([$project])
        ->and(Scopes::models(collect([$project, $project])))->toHaveCount(2)
        ->and(Scopes::models([]))->toBe([])
        ->and(fn () => Scopes::models([$project, 5]))->toThrow(PermissionException::class, 'int given');
});

it('casts stored dates as UTC regardless of the app time zone', function (): void {
    date_default_timezone_set('Europe/Bratislava');

    try {
        $cast = new UtcDateTime;
        $model = new RoleAssignment;

        $read = $cast->get($model, 'valid_until', '2026-11-10 10:00:00', []);

        expect($read?->getTimezone()->getName())->toBe('UTC')
            ->and($read?->format('H:i:s'))->toBe('10:00:00')
            ->and($cast->get($model, 'valid_until', '2026-11-10 10:00:00.000000', [])?->format('H:i:s'))->toBe('10:00:00')
            ->and($cast->get($model, 'valid_until', null, []))->toBeNull()
            ->and($cast->get($model, 'valid_until', '', []))->toBeNull()
            ->and($cast->get($model, 'valid_until', new DateTimeImmutable('2026-11-10 11:00:00', new DateTimeZone('Europe/Bratislava')), [])?->format('H:i'))->toBe('10:00')
            ->and($cast->set($model, 'valid_until', CarbonImmutable::parse('2026-11-10 11:00:00.7', 'Europe/Bratislava'), []))->toBe('2026-11-10 10:00:00')
            ->and($cast->set($model, 'valid_until', null, []))->toBeNull()
            ->and(fn () => $cast->get($model, 'valid_until', 'not a date', []))->toThrow(PermissionException::class, 'not a date')
            ->and(fn () => $cast->get($model, 'valid_until', 12345, []))->toThrow(PermissionException::class, 'not a date');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('reads the scope-key type from config, defaulting to the holder key type', function (): void {
    config()->set('permissions.key_type', 'uuid');
    expect(PermissionRegistrar::scopeKeyType()->value)->toBe('uuid');

    config()->set('permissions.assignments.scope_key_type', 'ulid');
    expect(PermissionRegistrar::scopeKeyType()->value)->toBe('ulid');

    config()->set('permissions.assignments.scope_key_type', 'nope');
    expect(fn () => PermissionRegistrar::scopeKeyType())
        ->toThrow(InvalidConfigurationException::class);
});

it('refuses a non-boolean assignments flag', function (): void {
    config()->set('permissions.assignments.enabled', 'sometimes');

    expect(fn () => PermissionRegistrar::assignmentsEnabled())
        ->toThrow(InvalidConfigurationException::class);
});

it('keeps a holder without a key out of the memo', function (): void {
    $resolver = app(GrantResolver::class);

    expect($resolver->rows(new User))->toBe([]);

    $resolver->forget(new User); // no-op, no error
});
