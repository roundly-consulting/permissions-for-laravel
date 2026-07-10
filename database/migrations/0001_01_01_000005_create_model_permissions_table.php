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
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained($this->permissions())->cascadeOnDelete();
            $table->string('model_type');
            PermissionRegistrar::modelKeyType()->defineModelId($table);

            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
    }

    private function table(): string
    {
        $name = config('permissions.table_names.model_permissions', 'model_permissions');

        return is_string($name) ? $name : 'model_permissions';
    }

    private function permissions(): string
    {
        $name = config('permissions.table_names.permissions', 'permissions');

        return is_string($name) ? $name : 'permissions';
    }
};
