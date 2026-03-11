<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->catchPhrase(),
            'color' => fake()->hexColor(),
            'budget_hours' => fake()->optional()->randomFloat(2, 10, 200),
            'hourly_rate' => fake()->optional()->randomFloat(2, 80, 200),
            'is_billable' => true,
            'is_active' => true,
        ];
    }
}
