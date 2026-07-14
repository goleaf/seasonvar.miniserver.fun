<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleReview;
use App\Models\Director;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogHomeMetricsCache;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class CatalogRelatedContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_suggestions_are_normalized_validated_and_bounded_by_type(): void
    {
        $titles = collect(range(1, 7))->map(fn (int $index): CatalogTitle => CatalogTitle::factory()->create([
            'title' => "Alfa Story {$index}",
            'slug' => "alfa-story-{$index}",
        ]));

        foreach (range(1, 7) as $index) {
            $actor = Actor::query()->create([
                'name' => "Alfa Actor {$index}",
                'slug' => "alfa-actor-{$index}",
            ]);
            $director = Director::query()->create([
                'name' => "Alfa Director {$index}",
                'slug' => "alfa-director-{$index}",
            ]);
            $titles->get($index - 1)->actors()->attach($actor);
            $titles->get($index - 1)->directors()->attach($director);
        }

        $response = $this->getJson('/api/v1/search/suggestions?q=%20%20Alfa%20%20')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.query', 'Alfa')
            ->assertJsonStructure(['data' => [['type', 'label', 'slug', 'title_slug', 'count']]]);

        $items = collect($response->json('data'));
        $this->assertCount(5, $items->where('type', 'title'));
        $this->assertCount(5, $items->where('type', 'actor'));
        $this->assertCount(5, $items->where('type', 'director'));

        foreach (['q=A', 'q='.str_repeat('a', 81)] as $query) {
            $this->getJson('/api/v1/search/suggestions?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_recommendations_are_ranked_visible_and_hide_scoring_internals(): void
    {
        config()->set('seasonvar.recommendations.max_per_title', 2);
        $source = CatalogTitle::factory()->create(['slug' => 'recommendation-source']);
        $first = CatalogTitle::factory()->create(['slug' => 'first-recommendation']);
        $second = CatalogTitle::factory()->create(['slug' => 'second-recommendation']);
        $hidden = CatalogTitle::factory()->create([
            'slug' => 'hidden-recommendation',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $this->storeRecommendation($source, $second, 2, 700, [
            'actor' => ['count' => 1, 'score' => 180],
        ]);
        $this->storeRecommendation($source, $first, 1, 900, [
            'theme_romance' => ['score' => 360],
            'private-algorithm-marker' => ['score' => 9999],
        ]);
        $this->storeRecommendation($source, $hidden, 3, 990, [
            'source_signal' => ['score' => 500],
        ]);

        $response = $this->getJson('/api/v1/titles/recommendation-source/recommendations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.reasons.0', 'Романтика')
            ->assertJsonPath('data.0.title.slug', 'first-recommendation')
            ->assertJsonPath('data.1.rank', 2)
            ->assertJsonPath('data.1.title.slug', 'second-recommendation')
            ->assertJsonMissing(['slug' => 'hidden-recommendation']);

        foreach (['score', 'algorithm_version', 'metadata_score', 'source_score', 'quality_score', 'private-algorithm-marker'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
    }

    public function test_reviews_are_paginated_and_exclude_source_identity_and_hashes(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'reviewed-title']);
        $sourceUrl = 'https://seasonvar.ru/private-review-source';
        $sourcePage = SourcePage::factory()->create([
            'url' => $sourceUrl,
            'url_hash' => hash('sha256', $sourceUrl),
        ]);

        foreach ([
            ['author' => 'Второй автор', 'body' => 'Второй отзыв', 'date' => now()->subDay()],
            ['author' => 'Первый автор', 'body' => 'Первый отзыв', 'date' => now()],
        ] as $review) {
            CatalogTitleReview::query()->create([
                'catalog_title_id' => $title->id,
                'source_page_id' => $sourcePage->id,
                'author' => $review['author'],
                'body' => $review['body'],
                'body_hash' => hash('sha256', $review['body']),
                'published_at' => $review['date'],
            ]);
        }

        $response = $this->getJson('/api/v1/titles/reviewed-title/reviews?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.author', 'Первый автор')
            ->assertJsonPath('data.0.body', 'Первый отзыв')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data' => [['id', 'author', 'body', 'published_at']], 'links', 'meta']);

        foreach ([$sourceUrl, 'source_page_id', 'body_hash', 'url_hash'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }

        foreach (['page=0', 'per_page=51'] as $query) {
            $this->getJson('/api/v1/titles/reviewed-title/reviews?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed');
        }

        $this->getJson('/api/v1/titles/unknown-title/reviews')->assertNotFound();
    }

    public function test_openapi_describes_search_recommendation_and_review_operations(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/search/suggestions.get.operationId', 'getSearchSuggestions')
            ->assertJsonPath('paths./api/v1/titles/{titleSlug}/recommendations.get.operationId', 'getCatalogTitleRecommendations')
            ->assertJsonPath('paths./api/v1/titles/{titleSlug}/reviews.get.operationId', 'getCatalogTitleReviews')
            ->assertJsonPath('components.schemas.SearchSuggestion.required.0', 'type')
            ->assertJsonPath('components.schemas.CatalogReview.required.0', 'id');
    }

    public function test_every_public_v1_endpoint_excludes_catalog_source_and_algorithm_internals(): void
    {
        $markers = [
            'https://seasonvar.ru/private-v1-title-source',
            str_repeat('a', 64),
            'private-v1-external-id',
            str_repeat('b', 64),
            'private-v1-storage-disk',
            'licensed/private-v1-path.mp4',
            'https://media.example.com/private-v1-playback.m3u8',
            'https://seasonvar.ru/private-v1-media-source',
            'private-v1-source-media-key',
            'private-v1-algorithm-version',
            'private-v1-reason-marker',
            str_repeat('c', 64),
            'https://seasonvar.ru/private-v1-review-source',
        ];
        $title = CatalogTitle::factory()->create([
            'slug' => 'privacy-v1-title',
            'title' => 'Privacy V1 Title',
            'source_url' => $markers[0],
            'source_url_hash' => $markers[1],
            'external_id' => $markers[2],
            'content_hash' => $markers[3],
        ]);
        $actor = Actor::query()->create([
            'name' => 'Privacy V1 Actor',
            'slug' => 'privacy-v1-actor',
            'source_url' => 'https://seasonvar.ru/private-v1-actor-source',
        ]);
        $title->actors()->attach($actor);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'source_url' => 'https://seasonvar.ru/private-v1-season-source',
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'source_url' => 'https://seasonvar.ru/private-v1-episode-source',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'storage_disk' => $markers[4],
            'path' => $markers[5],
            'playback_url' => $markers[6],
            'source_url' => $markers[7],
            'source_media_key' => $markers[8],
            'health_status' => 'degraded',
            'last_error_category' => 'timeout',
            'last_http_status' => 599,
            'published_at' => now(),
        ]);
        $recommended = CatalogTitle::factory()->create(['slug' => 'privacy-v1-recommended']);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $title->id,
            'recommended_title_id' => $recommended->id,
            'rank' => 1,
            'score' => 999,
            'algorithm_version' => $markers[9],
            'reasons' => [$markers[10] => ['score' => 999]],
            'computed_at' => now(),
        ]);
        $sourcePage = SourcePage::factory()->create([
            'url' => $markers[12],
            'url_hash' => hash('sha256', $markers[12]),
        ]);
        CatalogTitleReview::query()->create([
            'catalog_title_id' => $title->id,
            'source_page_id' => $sourcePage->id,
            'author' => 'Публичный автор',
            'body' => 'Публичный текст отзыва.',
            'body_hash' => $markers[11],
            'published_at' => now(),
        ]);

        app(CatalogHomeSnapshotCache::class)->refresh();
        app(CatalogHomeMetricsCache::class)->refresh();

        $responses = [
            $this->getJson('/api/v1/config'),
            $this->getJson('/api/v1/health'),
            $this->getJson('/api/v1/home'),
            $this->getJson('/api/v1/catalog/filters'),
            $this->getJson('/api/v1/catalog/directories'),
            $this->getJson('/api/v1/catalog/directories/actors'),
            $this->getJson('/api/v1/titles?per_page=50'),
            $this->getJson('/api/v1/titles/privacy-v1-title'),
            $this->getJson('/api/v1/titles/privacy-v1-title/seasons'),
            $this->getJson("/api/v1/titles/privacy-v1-title/seasons/{$season->id}/episodes"),
            $this->getJson('/api/v1/search/suggestions?q=Privacy'),
            $this->getJson('/api/v1/titles/privacy-v1-title/recommendations'),
            $this->getJson('/api/v1/titles/privacy-v1-title/reviews'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();

            foreach ($markers as $marker) {
                $response->assertDontSee($marker, false);
            }

            foreach ([
                'source_url',
                'source_url_hash',
                'content_hash',
                'external_id',
                'storage_disk',
                'playback_url',
                'source_media_key',
                'health_status',
                'last_http_status',
                'body_hash',
                'algorithm_version',
                'metadata_score',
                'quality_score',
            ] as $privateField) {
                $response->assertDontSee($privateField, false);
            }
        }
    }

    public function test_openapi_contains_every_v1_route_and_http_method(): void
    {
        $paths = (array) $this->getJson('/api/openapi.json')->assertOk()->json('paths');
        $v1Routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->reject(fn ($route): bool => $route->getName() === 'api.fallback')
            ->values();

        foreach ($v1Routes as $route) {
            $path = '/'.$route->uri();

            $this->assertArrayHasKey($path, $paths, "OpenAPI не описывает {$path}.");

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(
                    strtolower($method),
                    $paths[$path],
                    "OpenAPI не описывает {$method} {$path}.",
                );
            }
        }
    }

    /** @param array<string, array<string, int>> $reasons */
    private function storeRecommendation(
        CatalogTitle $source,
        CatalogTitle $recommended,
        int $rank,
        int $score,
        array $reasons,
    ): void {
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $recommended->id,
            'rank' => $rank,
            'score' => $score,
            'algorithm_version' => 'private-v99',
            'metadata_score' => $score,
            'source_score' => 10,
            'quality_score' => 20,
            'reasons' => $reasons,
            'computed_at' => now(),
        ]);
    }
}
