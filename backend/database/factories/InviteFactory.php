<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\Invite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token' => Invite::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Imposta il token in chiaro (il DB ne conserva solo l'hash).
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => ['token' => Invite::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }
}
