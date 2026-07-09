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
            $table->id();
            $table->string('name')->unique();
            $table->json('description')->nullable();
            $table->timestamps();
        });
    }

    private function table(): string
    {
        $name = config('permissions.table_names.roles', 'roles');

        return is_string($name) ? $name : 'roles';
    }
};
