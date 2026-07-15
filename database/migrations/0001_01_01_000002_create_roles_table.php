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
        Schema::create(PermissionRegistrar::rolesTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            // jsonb to match the translatable convention (Postgres uses jsonb; SQLite
            // and MySQL map it to text/json). `description` is a per-locale map.
            $table->jsonb('description')->nullable();
            $table->timestamps();
        });
    }
};
