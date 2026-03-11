<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeEntryFactory extends Factory
{
    public function definition(): array
    {
        $started = fake()->dateTimeBetween('-30 days', 'now');
        $duration = fake()->numberBetween(900, 14400); // 15 min to 4 hours

        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'description' => fake()->optional()->sentence(),
            'started_at' => $started,
            'stopped_at' => (clone $started)->modify("+{$duration} seconds"),
            'duration_seconds' => $duration,
            'is_billable' => true,
            'is_running' => false,
            'source' => 'web',
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'started_at' => now(),
            'stopped_at' => null,
            'duration_seconds' => null,
            'is_running' => true,
        ]);
    }
}
