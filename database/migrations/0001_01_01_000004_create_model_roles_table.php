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
            $table->foreignId('role_id')->constrained($this->roles())->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
    }

    private function table(): string
    {
        $name = config('permissions.table_names.model_roles', 'model_roles');

        return is_string($name) ? $name : 'model_roles';
    }

    private function roles(): string
    {
        $name = config('permissions.table_names.roles', 'roles');

        return is_string($name) ? $name : 'roles';
    }
};
