<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned `users` table that holds roles and permissions. This package ships no
 * migration that creates it — the host already has one — so this fixture stands in for the
 * host's own schema.
 *
 * It is a real migration rather than a `Schema::create()` in `defineDatabaseMigrations()`
 * for a reason the pgsql leg makes load-bearing: `PackageTestCase` resets a real engine by
 * dropping every table and re-migrating. A table built outside the migrator is dropped by
 * that reset and never rebuilt, so the second test in a Postgres run would find no `users`
 * table. On sqlite `:memory:` the old shape happened to work because the database dies
 * with the connection — which is also why it needed a hand-rolled
 * `beforeApplicationDestroyed(fn () => Schema::dropIfExists('users'))` teardown that the
 * base case now owns.
 *
 * It sorts first, so it exists before anything that references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
};
