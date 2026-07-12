<?php

namespace Database\Factories;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
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
            'kind' => ReleaseKind::Regular,
            'sort_order' => 0,
            'title' => fake()->sentence(3),
            'summary' => fake()->paragraph(),
            'publication_status' => PublicationStatus::Published,
            'audience' => ContentAudience::Public,
        ];
    }
}
