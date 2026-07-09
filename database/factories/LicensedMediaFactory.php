<?php

namespace Database\Factories;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicensedMedia>
 */
class LicensedMediaFactory extends Factory
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
            'title' => fake()->sentence(3),
            'storage_disk' => 'local',
            'path' => 'licensed/'.fake()->uuid().'.mp4',
            'duration_seconds' => fake()->numberBetween(1200, 3600),
            'status' => 'draft',
        ];
    }
}
