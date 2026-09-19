<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
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
            'image_path' => fn (array $attributes) => "families/{$attributes['family_id']}/photos/".Str::lower((string) Str::ulid()).'.jpg',
            'size_bytes' => 1024,
            'width' => 1080,
            'height' => 1350,
            'data' => fake()->unique()->date(),
            'data_speciale' => false,
            'didascalia' => fake()->optional()->sentence(),
            'is_draft' => false,
        ];
    }

    public function special(): static
    {
        return $this->state(fn (array $attributes) => ['data_speciale' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['is_draft' => true]);
    }
}
