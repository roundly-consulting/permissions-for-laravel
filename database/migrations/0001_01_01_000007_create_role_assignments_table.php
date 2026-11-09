<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

/**
 * 2.1 — roles granted inside a scope and/or between two moments. Read only while
 * `permissions.assignments.enabled` is on.
 *
 * - `scope_type` / `scope_id` null = unscoped. `scope_id` follows
 *   `assignments.scope_key_type` (default: `key_type`).
 * - `valid_from` (inclusive) / `valid_until` (exclusive) are UTC wall times, `dateTime` rather
 *   than `timestamp`: no MySQL session time-zone shift, no 2038 limit.
 * - `fingerprint` is the sha256 of the whole identity tuple (holder, role, scope, window), so
 *   every write is an idempotent upsert on one non-null unique column — portable across engines,
 *   with no NULL-is-distinct trap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PermissionRegistrar::roleAssignmentsTable(), function (Blueprint $table): void {
            // Explicit index names: the derived holder+scope one is 69 characters, past
            // MySQL's 64 (Postgres would silently truncate it to 63).
            $name = $table->getTable();

            $table->id();
            $table->foreignId('role_id')->constrained(PermissionRegistrar::rolesTable())->cascadeOnDelete();
            $table->string('model_type');
            $table->ownerKey('model_id', PermissionRegistrar::keyType(), index: false);
            $table->string('scope_type')->nullable();
            $table->ownerKey('scope_id', PermissionRegistrar::scopeKeyType(), nullable: true, index: false);
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();

            $table->index(['model_id', 'model_type', 'scope_type', 'scope_id'], $name.'_holder_scope_index');
            $table->index(['scope_type', 'scope_id'], $name.'_scope_index');
            $table->index('valid_until');
            $table->index('role_id');
        });
    }
};
