<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => 'usr_'.Str::lower(fake()->unique()->lexify('????????')),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Developer,
            'specialty' => fake()->randomElement([
                'Plataforma',
                'Backend',
                'Frontend',
                'QA',
                'Datos',
            ]),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Set the user's role to Admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Admin,
        ]);
    }

    /**
     * Set the user's role to Agent.
     */
    public function agent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Agent,
        ]);
    }

    /**
     * Set the user's role to Developer.
     */
    public function developer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Developer,
        ]);
    }
}
