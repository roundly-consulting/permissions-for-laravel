<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Permissions\Models\Role;
use RoundlyConsulting\Permissions\Support\RoleModel;

/**
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * Build the model the host configured in `permissions.models.role`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<Role>
     */
    public function modelName(): string
    {
        return RoleModel::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'role-'.$this->faker->unique()->slug(2),
            'description' => null,
        ];
    }
}
