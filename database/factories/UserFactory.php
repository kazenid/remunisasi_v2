<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sikep_user_id' => fake()->unique()->numberBetween(1, 999999),
            'username' => fake()->unique()->numerify('1######'),
            'nama' => fake()->name(),
            'no_absen' => fake()->unique()->numberBetween(1000000, 1999999),
            'is_admin' => false,
            'role' => null,
            'aktif' => true,
            'last_sync_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * State: user adalah Admin lokal (role+is_admin ditulis berpasangan).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
            'aktif' => true,
        ]);
    }

    /**
     * State: user ber-role Katimker.
     */
    public function katimker(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_KATIMKER,
            'is_admin' => false,
            'aktif' => true,
        ]);
    }

    /**
     * State: user ber-role Kabid.
     */
    public function kabid(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_KABID,
            'is_admin' => false,
            'aktif' => true,
        ]);
    }

    /**
     * State: user nonaktif.
     */
    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif' => false,
        ]);
    }
}
