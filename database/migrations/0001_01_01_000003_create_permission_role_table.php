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
        Schema::create(PermissionRegistrar::permissionRoleTable(), function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained(PermissionRegistrar::permissionsTable())->cascadeOnDelete();
            $table->foreignId('role_id')->constrained(PermissionRegistrar::rolesTable())->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
        });
    }
};
