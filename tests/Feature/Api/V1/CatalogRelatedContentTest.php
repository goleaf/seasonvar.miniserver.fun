<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleReview;
use App\Models\Director;
use App\Models\SourcePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
