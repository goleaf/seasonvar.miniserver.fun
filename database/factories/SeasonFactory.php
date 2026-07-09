<?php

namespace Database\Factories;

use App\Models\CatalogTitle;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_title_id' => CatalogTitle::factory(),
            'number' => fake()->numberBetween(1, 12),
            'title' => 'Season '.fake()->numberBetween(1, 12),
        ];
    }
}
