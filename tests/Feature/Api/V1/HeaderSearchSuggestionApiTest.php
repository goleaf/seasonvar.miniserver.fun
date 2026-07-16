<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HeaderSearchSuggestionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_title_scope_returns_public_rich_cards(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Полярный детектив',
            'slug' => 'poliarnyi-detektiv',
            'poster_url' => 'https://images.example.com/polar-detective.jpg',
            'year' => 2025,
        ]);
        $season = Season::factory()->for($title)->create();
        Episode::factory()
            ->count(3)
            ->for($season)
            ->sequence(
                ['number' => 1],
                ['number' => 2],
                ['number' => 3],
            )
            ->create();
        CatalogTitle::factory()->create([
            'title' => 'Полярный детектив закрытый',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $this->getJson('/api/v1/search/suggestions?scope=header_titles&q=Полярный')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.query', 'Полярный')
            ->assertJsonPath('meta.scope', 'header_titles')
            ->assertJsonPath('data.0.type', 'title')
            ->assertJsonPath('data.0.group', 'titles')
            ->assertJsonPath('data.0.label', 'Полярный детектив')
            ->assertJsonPath('data.0.poster_url', 'https://images.example.com/polar-detective.jpg')
            ->assertJsonPath('data.0.year', 2025)
            ->assertJsonPath('data.0.seasons_count', 1)
            ->assertJsonPath('data.0.episodes_count', 3)
            ->assertJsonPath('data.0.meta', '2025 · 1 сезон · 3 серии')
            ->assertJsonPath('data.0.url', route('titles.show', $title));
    }

    public function test_header_portal_scope_returns_public_non_title_results(): void
    {
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Полярный детектив',
            'slug' => 'poliarnyi-detektiv',
        ]);
        $title->genres()->attach($genre);

        $this->getJson('/api/v1/search/suggestions?scope=header_portal&q=Полярный')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'header_portal')
            ->assertJsonFragment([
                'type' => 'genre',
                'group' => 'directories',
                'label' => 'Полярный детектив',
                'url' => route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]),
            ]);
    }

    public function test_header_scope_is_allowlisted(): void
    {
        $this->getJson('/api/v1/search/suggestions?scope=private&q=Полярный')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    }
}
