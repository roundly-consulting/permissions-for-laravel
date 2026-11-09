<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Facades\Permissions;
use RoundlyConsulting\Permissions\Tests\Fixtures\Project;
use RoundlyConsulting\Permissions\Tests\Fixtures\User;
use RoundlyConsulting\Permissions\Tests\Fixtures\UuidProject;
use RoundlyConsulting\Permissions\Tests\Fixtures\UuidUser;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The 2.1 migrations on a database that already ran 2.0 — and on mixed holder / scope key
 * types — on whatever engine the leg runs: a scratch sqlite file, or the isolated probe
 * schema/database on Postgres and MySQL (never the suite's own tables).
 */
beforeEach(function (): void {
    $this->previousDefault = config('database.default');
    $this->scratch = null;

    if (DriverMatrix::driver() === 'sqlite') {
        $this->scratch = tempnam(sys_get_temp_dir(), 'permissions-upgrade-');
        config()->set('database.connections.upgrade', ['driver' => 'sqlite', 'database' => $this->scratch, 'prefix' => '', 'foreign_key_constraints' => true]);
    } else {
        config()->set('database.connections.upgrade', DriverMatrix::probeConnectionConfig(DriverMatrix::driver()));
        DriverMatrix::prepareProbe('upgrade');
    }

    DB::purge('upgrade');
    config()->set('database.default', 'upgrade');
    Schema::dropAllTables();
});

afterEach(function (): void {
    Schema::dropAllTables();
    DB::purge('upgrade');
    config()->set('database.default', $this->previousDefault);

    if ($this->scratch !== null) {
        @unlink($this->scratch);
    }
});

/** @return list<string> */
function packageMigrations(): array
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    return array_values($files);
}

function runPackageMigrations(int $from, int $to): void
{
    foreach (array_slice(packageMigrations(), $from, $to - $from) as $file) {
        (require $file)->up();
    }
}

it('upgrades a 2.0 database without touching its data, keeping global names unique', function (): void {
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    runPackageMigrations(0, 5);

    $permission = DB::table('permissions')->insertGetId(['name' => 'posts.edit']);
    $editor = DB::table('roles')->insertGetId(['name' => 'editor']);
    DB::table('roles')->insert(['name' => 'viewer']);
    DB::table('permission_role')->insert(['permission_id' => $permission, 'role_id' => $editor]);
    $user = DB::table('users')->insertGetId(['name' => 'ada']);
    DB::table('model_roles')->insert(['role_id' => $editor, 'model_type' => User::class, 'model_id' => $user]);

    runPackageMigrations(5, 8);

    expect(DB::table('roles')->orderBy('name')->get(['name', 'owner_type', 'owner_id', 'owner_key'])->map(fn ($row): array => (array) $row)->all())
        ->toBe([
            ['name' => 'editor', 'owner_type' => null, 'owner_id' => null, 'owner_key' => ''],
            ['name' => 'viewer', 'owner_type' => null, 'owner_id' => null, 'owner_key' => ''],
        ])
        ->and(DB::table('permission_role')->count())->toBe(1)
        ->and(DB::table('model_roles')->count())->toBe(1)
        ->and(fn () => DB::table('roles')->insert(['name' => 'editor']))->toThrow(QueryException::class);

    DB::table('roles')->insert(['name' => 'editor', 'owner_type' => 'tenant', 'owner_id' => 1, 'owner_key' => 'tenant:1']);
    DB::table('roles')->insert(['name' => 'editor', 'owner_type' => 'tenant', 'owner_id' => 2, 'owner_key' => 'tenant:2']);

    expect(fn () => DB::table('roles')->insert(['name' => 'editor', 'owner_type' => 'tenant', 'owner_id' => 1, 'owner_key' => 'tenant:1']))
        ->toThrow(QueryException::class)
        ->and(DB::table('roles')->where('name', 'editor')->count())->toBe(3);

    // The 2.0 grant still answers, with assignments on, through the 2.1 schema.
    config()->set('permissions.assignments.enabled', true);
    expect(User::query()->findOrFail($user)->hasPermissionTo('posts.edit'))->toBeTrue();
});

it('runs uuid holders in bigint scopes, and bigint holders in uuid scopes', function (string $keyType, string $scopeKeyType): void {
    config()->set('permissions.key_type', $keyType);
    config()->set('permissions.assignments.scope_key_type', $scopeKeyType);
    config()->set('permissions.assignments.enabled', true);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    Schema::create('uuid_users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
        $table->timestamps();
    });
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable();
        $table->string('name')->nullable();
    });
    Schema::create('uuid_projects', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name')->nullable();
    });
    runPackageMigrations(0, 8);

    [$holder, $scope] = $keyType === 'uuid'
        ? [UuidUser::query()->create(['name' => 'ada']), Project::query()->create(['name' => 'A'])]
        : [User::query()->create(['name' => 'ada']), UuidProject::query()->create(['name' => 'A'])];

    Permissions::permission('posts.edit');
    Permissions::role('editor')->givePermissionTo('posts.edit');
    Permissions::owner($scope)->role('reviewer')->givePermissionTo('posts.edit');

    Permissions::for($holder)->in($scope)->until(now()->addDay())->assignRole('editor', 'reviewer');
    Permissions::for($holder)->in($scope)->givePermissionTo('posts.edit');

    expect(Permissions::for($holder)->in($scope)->hasRole(['editor']))->toBeTrue()
        ->and(Permissions::for($holder)->in($scope)->hasRole('reviewer'))->toBeTrue()
        ->and($holder->hasPermissionIn($scope, 'posts.edit'))->toBeTrue()
        ->and($holder::query()->roleIn($scope, 'reviewer')->count())->toBe(1)
        ->and(Permissions::forgetScope($scope))->toBe(4)
        ->and(Permissions::pruneOrphans())->toBe(0);
})->with([
    'uuid holder, bigint scope' => ['uuid', 'bigint'],
    'bigint holder, uuid scope' => ['bigint', 'uuid'],
]);
