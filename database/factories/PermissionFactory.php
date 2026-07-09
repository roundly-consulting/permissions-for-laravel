<?php

declare(strict_types=1);

namespace RoundlyConsulting\Permissions\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Permissions\Models\Permission;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

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
