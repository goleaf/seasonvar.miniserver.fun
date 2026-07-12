<?php

namespace Database\Factories;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
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
            'kind' => ReleaseKind::Regular,
            'sort_order' => 0,
            'title' => 'Season '.fake()->numberBetween(1, 12),
            'publication_status' => PublicationStatus::Published,
            'audience' => ContentAudience::Public,
        ];
    }
}
