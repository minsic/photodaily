<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => $name,
            'max_photos' => null,
            'max_storage_mb' => null,
            'price_monthly_cents' => 0,
            'is_active' => true,
        ];
    }

    public function limited(?int $photos = null, ?int $storageMb = null): static
    {
        return $this->state(fn (array $attributes) => [
            'max_photos' => $photos,
            'max_storage_mb' => $storageMb,
        ]);
    }
}
