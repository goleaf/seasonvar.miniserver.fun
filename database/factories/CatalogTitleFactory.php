<?php

namespace Database\Factories;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Source;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatalogTitle>
 */
class CatalogTitleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);
        $url = fake()->unique()->url();

        return [
            'source_id' => Source::factory(),
            'source_page_id' => SourcePage::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'slug' => Str::slug($title),
            'title' => $title,
            'type' => 'serial',
            'year' => fake()->numberBetween(1990, (int) date('Y')),
            'description' => fake()->paragraph(),
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
            'is_published' => true,
            'publication_status' => PublicationStatus::Published,
            'audience' => ContentAudience::Public,
            'indexed_at' => now(),
        ];
    }
}
