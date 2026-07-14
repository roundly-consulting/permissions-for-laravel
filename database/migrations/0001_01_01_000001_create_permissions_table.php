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
        Schema::create(PermissionRegistrar::permissionsTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->json('description')->nullable();
            $table->timestamps();
        });
    }
};
