<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Permissions\Models\Role;

/**
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

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
