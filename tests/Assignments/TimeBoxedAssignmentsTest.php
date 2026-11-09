<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Permissions\Exceptions\PermissionException;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));

    Permissions::permission('posts.edit');
    Permissions::permission('reports.export');
    Permissions::role('editor')->givePermissionTo('posts.edit');

    $this->user = User::query()->create(['name' => 'ada']);
    $this->project = Project::query()->create(['name' => 'A']);
});

it('opens at from (inclusive) and closes at until (exclusive) on the same loaded instance', function (): void {
    $from = CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC');
    $until = CarbonImmutable::parse('2026-11-10 17:00:00', 'UTC');

    Permissions::for($this->user)->in($this->project)->between($from, $until)->assignRole('editor');
    Permissions::for($this->user)->between($from, $until)->givePermissionTo('reports.export');

    $user = User::query()->findOrFail($this->user->getKey()); // loaded once, checked over time
    $scoped = fn (): bool => Permissions::for($user)->in($this->project)->hasPermissionTo('posts.edit');

    $this->travelTo($from->subSecond());
    expect($scoped())->toBeFalse()
        ->and($user->hasPermissionTo('reports.export'))->toBeFalse();

    $this->travelTo($from);
    expect($scoped())->toBeTrue()
        ->and($user->hasPermissionTo('reports.export'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('reports.export'))->toBeTrue();

    $this->travelTo($until->subSecond());
    expect($scoped())->toBeTrue();

    $this->travelTo($until);
    expect($scoped())->toBeFalse()
        ->and($user->hasPermissionTo('reports.export'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('reports.export'))->toBeFalse();
});

it('answers as of another moment with at()', function (): void {
    Permissions::for($this->user)->in($this->project)->until(now()->addDay())->assignRole('editor');

    $handle = Permissions::for($this->user)->in($this->project);

    expect($handle->at(now()->addHours(23))->hasRole('editor'))->toBeTrue()
        ->and($handle->at(now()->addDay())->hasRole('editor'))->toBeFalse()
        ->and($handle->at(now()->addDay())->at(null)->hasRole('editor'))->toBeTrue();
});

it('keeps separate rows for separate windows and grants while any is open', function (): void {
    $handle = Permissions::for($this->user)->in($this->project);

    $handle->between(now()->addDay(), now()->addDays(2))->assignRole('editor');
    $handle->between(now()->addDays(5), now()->addDays(6))->assignRole('editor');
    $handle->between(now()->addDays(5), now()->addDays(6))->assignRole('editor'); // identical: no new row

    expect(DB::table('role_assignments')->count())->toBe(2);

    $this->travel(1)->days();
    expect($handle->hasRole('editor'))->toBeTrue();

    $this->travel(2)->days();
    expect($handle->hasRole('editor'))->toBeFalse();

    $this->travel(2)->days();
    expect($handle->hasRole('editor'))->toBeTrue();
});

it('composes from() and until() in either order', function (): void {
    Permissions::for($this->user)->until(now()->addDays(2))->from(now()->addDay())->assignRole('editor');

    $row = DB::table('role_assignments')->first();

    expect($row->valid_from)->toStartWith('2026-11-10 12:00:00')
        ->and($row->valid_until)->toStartWith('2026-11-11 12:00:00');
});

it('refuses a window that does not end after it starts', function (): void {
    expect(fn () => Permissions::for($this->user)->between(now()->addDay(), now()->addDay()))
        ->toThrow(PermissionException::class, 'must end after it starts')
        ->and(fn () => Permissions::for($this->user)->from(now()->addDays(2))->until(now()->addDay()))
        ->toThrow(PermissionException::class, 'must end after it starts');
});

it('refuses to write a window that is already over', function (): void {
    expect(fn () => Permissions::for($this->user)->until(now())->assignRole('editor'))
        ->toThrow(PermissionException::class, 'already over')
        ->and(fn () => Permissions::for($this->user)->between(now()->subDays(2), now()->subDay())->givePermissionTo('reports.export'))
        ->toThrow(PermissionException::class, 'already over')
        ->and(DB::table('role_assignments')->count())->toBe(0)
        ->and(DB::table('permission_assignments')->count())->toBe(0);
});

it('stores UTC wall time whatever the app and input time zones are', function (): void {
    config()->set('app.timezone', 'Europe/Bratislava');
    date_default_timezone_set('Europe/Bratislava');

    try {
        $until = CarbonImmutable::parse('2026-11-12 09:30:15.750', 'America/New_York');

        Permissions::for($this->user)->in($this->project)->until($until)->assignRole('editor');

        expect(DB::table('role_assignments')->value('valid_until'))->toStartWith('2026-11-12 14:30:15');

        $assignment = Permissions::for($this->user)->assignments()->first();

        expect($assignment->validUntil?->getTimezone()->getName())->toBe('UTC')
            ->and($assignment->validUntil?->format('Y-m-d H:i:s'))->toBe('2026-11-12 14:30:15')
            ->and($assignment->validUntil?->equalTo($until->startOfSecond()))->toBeTrue();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 15:30:14', 'Europe/Bratislava'));
        expect(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeTrue();

        $this->travelTo(CarbonImmutable::parse('2026-11-12 15:30:15', 'Europe/Bratislava'));
        expect(Permissions::for($this->user)->in($this->project)->hasRole('editor'))->toBeFalse();
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('prunes ended rows only, with a grace period', function (): void {
    Permissions::for($this->user)->until(now()->addHour())->assignRole('editor');
    Permissions::for($this->user)->in($this->project)->until(now()->addDays(40))->assignRole('editor');
    Permissions::for($this->user)->until(now()->addHours(2))->givePermissionTo('reports.export');
    Permissions::for($this->user)->in($this->project)->assignRole('editor'); // open-ended

    $this->travel(3)->hours();

    expect(Permissions::pruneExpired(now()->subDays(30)))->toBe(0)
        ->and(Permissions::pruneExpired())->toBe(2)
        ->and(DB::table('role_assignments')->count())->toBe(2)
        ->and(DB::table('permission_assignments')->count())->toBe(0);
});

it('prunes ended rows from the command', function (): void {
    Permissions::for($this->user)->until(now()->addHour())->assignRole('editor');
    Permissions::for($this->user)->until(now()->addDays(2))->givePermissionTo('reports.export');

    $this->travel(1)->days();

    expect(Artisan::call('permissions:prune-expired', ['--after' => '2 days']))->toBe(0)
        ->and(Artisan::output())->toContain('Pruned 0 expired assignment row(s).')
        ->and(Artisan::call('permissions:prune-expired'))->toBe(0)
        ->and(Artisan::output())->toContain('Pruned 1 expired assignment row(s).');
});

it('refuses an --after that is not a positive duration', function (string $after): void {
    expect(Artisan::call('permissions:prune-expired', ['--after' => $after]))->toBe(2)
        ->and(Artisan::output())->toContain('Cannot read --after');
})->with(['soon', '0 days']);
