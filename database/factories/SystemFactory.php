<?php

namespace Database\Factories;

use App\Enums\AppHealth;
use App\Models\System;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<System>
 */
class SystemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'id' => 'app-'.$slug,
            'name' => Str::title(str_replace('-', ' ', $slug)),
            'domain' => $slug.'.example.io',
            'lead_user_id' => User::factory(),
            'health' => AppHealth::Operational,
            'uptime' => fake()->randomFloat(2, 98.50, 99.99),
            'api_key' => System::generateApiKey(),
        ];
    }

    /**
     * Indicate that the system is operational.
     */
    public function operational(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health' => AppHealth::Operational,
            'uptime' => fake()->randomFloat(2, 99.50, 99.99),
        ]);
    }

    /**
     * Indicate that the system is experiencing degraded performance.
     */
    public function degraded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health' => AppHealth::Degraded,
            'uptime' => fake()->randomFloat(2, 96.00, 98.99),
        ]);
    }

    /**
     * Indicate that the system is in an alert state.
     */
    public function alert(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health' => AppHealth::Alert,
            'uptime' => fake()->randomFloat(2, 90.00, 95.99),
        ]);
    }
}
