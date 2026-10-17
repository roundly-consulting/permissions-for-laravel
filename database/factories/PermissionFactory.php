<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Permissions\Models\Permission;
use RoundlyConsulting\Permissions\Support\PermissionModel;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * Build the model the host configured in `permissions.models.permission`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<Permission>
     */
    public function modelName(): string
    {
        return PermissionModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'permission-'.$this->faker->unique()->slug(2),
            'description' => null,
        ];
    }
}
