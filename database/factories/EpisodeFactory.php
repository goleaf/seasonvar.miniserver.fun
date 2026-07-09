<?php

namespace Database\Factories;

use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Episode>
 */
class EpisodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'number' => fake()->numberBetween(1, 30),
            'title' => fake()->sentence(3),
            'summary' => fake()->paragraph(),
        ];
    }
}
