<?php

namespace Database\Factories;

use App\Models\Reporter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reporter>
 */
class ReporterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'usr_'.fake()->unique()->numberBetween(1000, 99999),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
        ];
    }
}
