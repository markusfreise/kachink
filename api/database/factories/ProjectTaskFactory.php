<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'priority' => fake()->randomElement(['immediate', 'urgent', 'soon', 'easy']),
            'estimate_minutes' => fake()->optional()->numberBetween(15, 960),
            'budget' => fake()->optional()->randomFloat(2, 100, 5000),
            'deadline' => fake()->optional()->dateTimeBetween('now', '+2 months')?->format('Y-m-d'),
        ];
    }
}
