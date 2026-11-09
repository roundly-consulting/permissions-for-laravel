<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-owned scope models for the 2.1 assignment tests: tenants own projects; teams share key
 * values with projects on purpose (same id, different morph type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name')->nullable();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }
};
