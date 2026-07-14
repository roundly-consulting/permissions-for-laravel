<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Permissions\Support\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PermissionRegistrar::modelRolesTable(), function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained(PermissionRegistrar::rolesTable())->cascadeOnDelete();
            $table->string('model_type');

            // The holder's key column, typed from `permissions.key_type`. `index: false`
            // because the composite `[model_id, model_type]` index below is the one the
            // morph lookups use — a second single-column index would be dead weight.
            $table->ownerKey('model_id', PermissionRegistrar::keyType(), index: false);

            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
    }
};
