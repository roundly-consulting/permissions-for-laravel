<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * 2.1 — roles get an optional owner (a tenant, a team), so two owners can each define their
 * own `reviewer`.
 *
 * Name uniqueness moves from `name` to `(owner_key, name)`. `owner_key` is a plain string —
 * `''` for a global role, `"<morph class>:<key>"` for an owned one — rather than a unique
 * index over the nullable `owner_type` / `owner_id` pair: MySQL and Postgres before 15 treat
 * NULLs as distinct, which would allow two global `admin` rows. Existing rows get `''`, and
 * their names are already unique, so the new index always builds.
 *
 * The new unique index is created before the old one is dropped, so uniqueness never lapses;
 * the plain `name` index keeps the 2.0 `where name = ?` lookups indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(PermissionRegistrar::rolesTable(), function (Blueprint $table): void {
            $table->string('owner_type')->nullable();
            $table->ownerKey('owner_id', PermissionRegistrar::scopeKeyType(), nullable: true, index: false);
            $table->string('owner_key')->default('');

            $table->unique(['owner_key', 'name']);
            $table->index(['owner_type', 'owner_id']);
            $table->index('name');
        });

        Schema::table(PermissionRegistrar::rolesTable(), function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });
    }
};
