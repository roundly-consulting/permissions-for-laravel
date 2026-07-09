<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained($this->permissions())->cascadeOnDelete();
            $table->foreignId('role_id')->constrained($this->roles())->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id']);
        });
    }

    private function table(): string
    {
        $name = config('permissions.table_names.permission_role', 'permission_role');

        return is_string($name) ? $name : 'permission_role';
    }

    private function permissions(): string
    {
        $name = config('permissions.table_names.permissions', 'permissions');

        return is_string($name) ? $name : 'permissions';
    }

    private function roles(): string
    {
        $name = config('permissions.table_names.roles', 'roles');

        return is_string($name) ? $name : 'roles';
    }
};
