<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Konzeption', 'Design', 'Entwicklung', 'Projektmanagement',
                'Meeting', 'Support', 'Content', 'QA',
            ]) . ' ' . fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
        ];
    }
}
