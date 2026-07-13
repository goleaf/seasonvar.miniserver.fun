<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class ApiCatalogTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_titles_index_returns_paginated_resources_without_sensitive_fields(): void
    {
        $sourceUrl = 'https://seasonvar.ru/serial-901-Skrytyj_api_source-1-season.html';
        $mediaUrl = 'https://cdn.example.com/private-api-playback.m3u8';
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'api-test-title',
            'title' => 'API тест',
            'original_title' => 'API Test',
            'type' => 'serial',
            'year' => 2024,
            'poster_url' => 'https://media.example.com/api-poster.jpg',
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'content_hash' => hash('sha256', 'private-api-content'),
            'is_published' => true,
            'indexed_at' => now(),
        ]);
        $genre = Genre::query()->create([
            'name' => 'API жанр',
            'slug' => 'api-zhanr',
            'source_url' => 'https://seasonvar.ru/genre-private-api.html',
        ]);
        $catalogTitle->genres()->attach($genre);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'status' => 'published',
            'path' => 'licensed/private-api-video.mp4',
            'playback_url' => $mediaUrl,
            'source_url' => 'https://seasonvar.ru/video-private-api.html',
            'published_at' => now(),
        ]);
        CatalogTitle::factory()->create([
            'slug' => 'api-draft-title',
            'title' => 'Черновик API',
            'is_published' => false,
        ]);

        $response = $this->getJson('/api/titles?per_page=10');

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json): AssertableJson => $json
                ->has('data', 1)
                ->has('data.0', fn (AssertableJson $json): AssertableJson => $json
                    ->where('id', $catalogTitle->id)
                    ->where('slug', 'api-test-title')
                    ->where('title', 'API тест')
                    ->where('original_title', 'API Test')
                    ->where('type', 'serial')
                    ->where('year', 2024)
                    ->where('poster_url', 'https://media.example.com/api-poster.jpg')
                    ->where('counts.seasons', 0)
                    ->where('counts.episodes', 0)
                    ->where('counts.published_media', 1)
                    ->has('taxonomies.genres', 1, fn (AssertableJson $json): AssertableJson => $json
                        ->where('type', 'genre')
                        ->where('name', 'API жанр')
                        ->where('slug', 'api-zhanr')
                        ->missing('source_url')
                        ->etc())
                    ->missing('external_id')
                    ->missing('source_url')
                    ->missing('source_url_hash')
                    ->missing('content_hash')
                    ->missing('licensed_media')
                    ->has('links.self')
                    ->has('links.web')
                    ->etc())
                ->has('links')
                ->has('meta')
                ->etc());

        $response
            ->assertDontSee($sourceUrl, false)
            ->assertDontSee($mediaUrl, false)
            ->assertDontSee('https://seasonvar.ru/genre-private-api.html', false)
            ->assertDontSee('https://seasonvar.ru/video-private-api.html', false)
            ->assertDontSee('licensed/private-api-video.mp4', false)
            ->assertDontSee('private-api-content', false)
            ->assertDontSee('api-draft-title', false);
    }

    public function test_title_show_returns_loaded_public_relationships_without_raw_media_urls(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'api-show-title',
            'title' => 'API сериал',
            'is_published' => true,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
            'episodes_released' => 1,
            'episodes_total' => 8,
            'translation_name' => 'AniDub',
            'latest_episode_released_at' => now()->toDateString(),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Серия 1',
            'summary' => 'Описание серии',
            'released_at' => now()->toDateString(),
            'source_url' => 'https://seasonvar.ru/episode-private-api.html',
        ]);
        Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
            'title' => 'Скрытый сезон',
            'publication_status' => PublicationStatus::Hidden,
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Будущая серия',
            'available_from' => now()->addDay(),
        ]);
        $deletedEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 3,
            'title' => 'Удалённая серия',
        ]);
        $deletedEpisode->delete();
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'path' => 'licensed/private-show-video.mp4',
            'playback_url' => 'https://cdn.example.com/private-show-playback.m3u8',
            'source_url' => 'https://seasonvar.ru/video-private-show.html',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/titles/api-show-title');

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json): AssertableJson => $json
                ->has('data', fn (AssertableJson $json): AssertableJson => $json
                    ->where('slug', 'api-show-title')
                    ->where('counts.seasons', 1)
                    ->where('counts.episodes', 1)
                    ->where('counts.published_media', 1)
                    ->has('seasons', 1, fn (AssertableJson $json): AssertableJson => $json
                        ->where('number', 1)
                        ->where('title', 'Сезон 1')
                        ->where('episodes_released', 1)
                        ->where('episodes_total', 8)
                        ->where('translation_name', 'AniDub')
                        ->has('episodes', 1, fn (AssertableJson $json): AssertableJson => $json
                            ->where('number', 1)
                            ->where('title', 'Серия 1')
                            ->where('summary', 'Описание серии')
                            ->where('kind', 'regular')
                            ->missing('source_url')
                            ->etc())
                        ->missing('source_url')
                        ->etc())
                    ->missing('source_url')
                    ->missing('source_url_hash')
                    ->missing('content_hash')
                    ->missing('licensed_media')
                    ->etc()));

        $response
            ->assertDontSee('https://seasonvar.ru/episode-private-api.html', false)
            ->assertDontSee('https://seasonvar.ru/video-private-show.html', false)
            ->assertDontSee('https://cdn.example.com/private-show-playback.m3u8', false)
            ->assertDontSee('licensed/private-show-video.mp4', false);
        $response
            ->assertDontSee('Скрытый сезон', false)
            ->assertDontSee('Будущая серия', false)
            ->assertDontSee('Удалённая серия', false);
    }

    public function test_unpublished_titles_are_not_exposed_through_api(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'api-unpublished-title',
            'title' => 'Невидимый API сериал',
            'is_published' => false,
        ]);

        $this
            ->getJson('/api/titles/api-unpublished-title')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_titles_index_validates_pagination_parameters_as_json(): void
    {
        $this
            ->getJson('/api/titles?per_page=200&page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
    }

    public function test_people_lookup_returns_only_public_actor_options_with_counts_and_no_internal_fields(): void
    {
        $actor = Actor::query()->create([
            'name' => 'Иван Поисковый',
            'slug' => 'ivan-poiskovyi',
            'source_url' => 'https://seasonvar.ru/private-actor-source',
        ]);
        $hiddenOnlyActor = Actor::query()->create([
            'name' => 'Иван Скрытый',
            'slug' => 'ivan-skrytyi',
        ]);
        $publicTitle = CatalogTitle::factory()->create();
        $hiddenTitle = CatalogTitle::factory()->create([
            'publication_status' => PublicationStatus::Hidden,
            'is_published' => false,
        ]);
        $publicTitle->actors()->attach($actor);
        $hiddenTitle->actors()->attach([$actor->id, $hiddenOnlyActor->id]);

        $this->getJson('/api/catalog/people?type=actor&q=Иван')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json): AssertableJson => $json
                ->has('data', 1)
                ->has('data.0', fn (AssertableJson $json): AssertableJson => $json
                    ->where('type', 'actor')
                    ->where('slug', 'ivan-poiskovyi')
                    ->where('name', 'Иван Поисковый')
                    ->where('count', 1)
                    ->missing('id')
                    ->missing('source_url')));
    }

    public function test_people_lookup_validates_type_query_shape_and_length_as_json(): void
    {
        $this->getJson('/api/catalog/people?type=genre&q=Иван')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
        $this->getJson('/api/catalog/people?type=actor&q=Я')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/catalog/people?type=director&q='.str_repeat('а', 81))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/catalog/people?type[]=actor&q[]=Иван')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'q']);
    }

    public function test_people_lookup_is_hard_limited_to_twenty_options(): void
    {
        $title = CatalogTitle::factory()->create();

        foreach (range(1, 25) as $index) {
            $actor = Actor::query()->create([
                'name' => sprintf('Общий актёр %02d', $index),
                'slug' => sprintf('obshchii-akter-%02d', $index),
            ]);
            $title->actors()->attach($actor);
        }

        $this->getJson('/api/catalog/people?type=actor&q=Общий')
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }
}
