<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationListItem;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationFeedbackOptionQuery;
use App\Services\Catalog\CatalogTitlePageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogRecommendationListTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_builder_exposes_ranked_precomputed_recommendations_as_list_items(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 2]);
        $source = CatalogTitle::factory()->create();
        $first = $this->recommendableTitle('Первый совет');
        $second = $this->recommendableTitle('Второй совет');
        $third = $this->recommendableTitle('Третий совет');

        $this->storeRecommendation($source, $second, 2, 780, [
            'actor' => ['count' => 1, 'score' => 230],
        ]);
        $this->storeRecommendation($source, $first, 1, 920, [
            'theme_romance' => ['score' => 400],
            'director' => ['count' => 1, 'score' => 350],
            'actor' => ['count' => 2, 'score' => 300],
            'country' => ['count' => 1, 'score' => 250],
        ]);
        $this->storeRecommendation($source, $third, 3, 700, [
            'genre' => ['count' => 1, 'score' => 180],
        ]);

        $data = app(CatalogTitlePageBuilder::class)->data($source, null);
        $items = $data['recommendationItems'];

        $this->assertNotEmpty($items);
        $this->assertInstanceOf(CatalogRecommendationListItem::class, $items->first());
        $this->assertSame([$first->id, $second->id], $items->pluck('title.id')->all());
        $this->assertSame([1, 2], $items->pluck('rank')->all());
        $this->assertSame([
            'Романтика',
            'Общий режиссёр',
            'Общие актёры',
            'Та же страна производства',
        ], $items->first()->reasonLabels);
        $this->assertSame(920, $items->first()->score);
        $this->assertArrayNotHasKey('recommendedTitleRecommendations', $data);
        $this->assertArrayNotHasKey('genreRecommendations', $data);
        $this->assertArrayNotHasKey('yearRecommendations', $data);
    }

    public function test_page_builder_returns_a_deduplicated_watchable_genre_fallback(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 4]);
        $genre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia-list']);
        $source = CatalogTitle::factory()->create(['year' => 2020]);
        $source->genres()->attach($genre);
        $both = CatalogTitle::factory()->create(['title' => 'Совпадает дважды', 'year' => 2020, 'indexed_at' => now()]);
        $both->genres()->attach($genre);
        $genreOnly = CatalogTitle::factory()->create(['title' => 'Только жанр', 'year' => 2019, 'indexed_at' => now()->subMinute()]);
        $genreOnly->genres()->attach($genre);
        $yearOnly = CatalogTitle::factory()->create(['title' => 'Только год', 'year' => 2020, 'indexed_at' => now()->subMinutes(2)]);
        LicensedMedia::factory()->create(['catalog_title_id' => $both->id, 'status' => 'published']);
        LicensedMedia::factory()->create(['catalog_title_id' => $genreOnly->id, 'status' => 'published']);
        LicensedMedia::factory()->create(['catalog_title_id' => $yearOnly->id, 'status' => 'published']);

        $items = app(CatalogTitlePageBuilder::class)->data($source, null)['recommendationItems'];

        $this->assertSame([$both->id, $genreOnly->id], $items->pluck('title.id')->all());
        $this->assertSame([1, 2], $items->pluck('rank')->all());
        $this->assertSame(['Похожие жанры и темы'], $items->first()->reasonLabels);
        $this->assertSame(['Похожие жанры и темы'], $items->get(1)->reasonLabels);
        $this->assertNotNull($items->first()->score);
    }

    public function test_title_page_renders_one_ranked_recommendation_list_with_uncropped_portrait_posters(): void
    {
        $source = CatalogTitle::factory()->create(['title' => 'Главный сериал']);
        $first = $this->recommendableTitle('Первый точный совет', [
            'poster_url' => 'https://media.example.com/first.jpg',
            'description' => 'Легкая история любви и отношений молодых героев.',
        ]);
        $second = $this->recommendableTitle('Второй точный совет', [
            'poster_url' => 'https://media.example.com/second.jpg',
            'description' => 'Дружба постепенно превращается в романтическую историю.',
        ]);
        $this->storeRecommendation($source, $second, 2, 800, ['actor' => ['count' => 1, 'score' => 230]]);
        $this->storeRecommendation($source, $first, 1, 950, ['theme_romance' => ['score' => 360]]);

        $response = $this->get(route('titles.show', $source))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-recommendation-list'));
        $this->assertSame(2, substr_count($html, 'data-recommendation-row'));
        $this->assertStringContainsString('data-ui-poster-layout="recommendation"', $html);
        $this->assertStringContainsString('aspect-[2/3]', $html);
        $this->assertStringContainsString('object-contain object-center', $html);
        $this->assertStringNotContainsString('aspect-[16/10]', $html);
        $this->assertStringNotContainsString('scale-[1.02]', $html);
        $this->assertStringContainsString('Романтика', $html);
        $this->assertStringContainsString('Почему похож', $html);
        $this->assertStringContainsString('Легкая история любви', $html);
        $this->assertStringNotContainsString('data-recommendation-rank', $html);
        $this->assertSame(2, substr_count($html, 'Открыть сериал'));
        preg_match_all(
            '/<a(?=[^>]*data-title-card-details)(?=[^>]*title-card-action-primary)[^>]*>/',
            $html,
            $recommendationActions,
        );
        $this->assertCount(2, $recommendationActions[0]);
        $this->assertLessThan(strpos($html, 'Второй точный совет'), strpos($html, 'Первый точный совет'));
        $this->assertStringNotContainsString('Ближайшие совпадения', $html);
        $this->assertStringNotContainsString('По похожим жанрам', $html);
        $this->assertStringNotContainsString('За '.$source->year.' год', $html);
    }

    public function test_title_page_shows_six_recommendations_and_reveals_at_most_six_more_without_a_second_request(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 24]);
        $source = CatalogTitle::factory()->create(['title' => 'Источник двенадцати рекомендаций']);

        foreach (range(1, 14) as $rank) {
            $candidate = $this->recommendableTitle("Рекомендация {$rank}");
            $this->storeRecommendation(
                $source,
                $candidate,
                $rank,
                1000 - $rank,
                ['genre' => ['count' => 1, 'score' => 200]],
            );
        }

        $data = app(CatalogTitlePageBuilder::class)->data($source, null);

        $this->assertCount(12, $data['recommendationItems']);
        $this->assertCount(6, $data['primaryRecommendationItems']);
        $this->assertCount(6, $data['additionalRecommendationItems']);
        $this->assertSame(6, $data['additionalRecommendationCount']);
        $this->assertSame(range(1, 6), $data['primaryRecommendationItems']->pluck('rank')->all());
        $this->assertSame(range(7, 12), $data['additionalRecommendationItems']->pluck('rank')->all());

        $html = $this->get(route('titles.show', $source))->assertOk()->getContent();

        $this->assertSame(12, substr_count($html, 'data-recommendation-row'));
        $this->assertStringContainsString('data-recommendation-primary-list', $html);
        $this->assertStringContainsString('data-recommendation-more', $html);
        $this->assertStringContainsString('data-recommendation-additional-list', $html);
        $this->assertStringContainsString('Показать ещё 6', $html);
        $this->assertStringNotContainsString('Рекомендация 13', $html);
        $this->assertStringNotContainsString('Рекомендация 14', $html);
    }

    public function test_authenticated_title_recommendations_use_compact_feedback_without_subject_option_queries(): void
    {
        $user = User::factory()->create();
        $source = CatalogTitle::factory()->create(['title' => 'Источник рекомендаций']);
        $candidate = $this->recommendableTitle('Управляемая рекомендация');
        $genre = Genre::query()->create(['name' => 'Драма причины', 'slug' => 'feedback-option-drama']);
        $country = Country::query()->create(['name' => 'Страна причины', 'slug' => 'feedback-option-country']);
        $actor = Actor::query()->create(['name' => 'Актёр причины', 'slug' => 'feedback-option-actor']);
        $candidate->genres()->attach($genre);
        $candidate->countries()->attach($country);
        $candidate->actors()->attach($actor);
        $feedbackOptions = app(CatalogRecommendationFeedbackOptionQuery::class)->forTitles([$candidate]);
        $this->assertSame($genre->id, $feedbackOptions[$candidate->id]['genres'][0]['id'] ?? null);
        $this->assertSame($country->id, $feedbackOptions[$candidate->id]['countries'][0]['id'] ?? null);
        $this->assertSame($actor->id, $feedbackOptions[$candidate->id]['actors'][0]['id'] ?? null);
        $this->storeRecommendation($source, $candidate, 1, 900, [
            'genre' => ['count' => 1, 'score' => 300],
        ]);
        $page = app(CatalogTitlePageBuilder::class)->data($source, $user);

        $this->assertSame([], $page['recommendationItems']->first()?->feedbackOptions);

        $componentHtml = Blade::render(
            '<x-catalog.recommendation-feedback :title-id="$titleId" action="setRecommendationFeedback" :feedback-options="$options" />',
            ['titleId' => $candidate->id, 'options' => $feedbackOptions[$candidate->id]],
        );
        $this->assertStringContainsString('Драма причины', $componentHtml);

        $html = $this->actingAs($user)
            ->get(route('titles.show', $source))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Больше похожего', $html);
        $this->assertStringContainsString('Не похоже', $html);
        $this->assertStringContainsString(
            "wire:target=\"setRecommendationFeedback({$candidate->id}, 'more_like_this')\"",
            $html,
        );
        $this->assertStringContainsString(
            "setRecommendationFeedbackReason({$candidate->id}, 'not_similar')",
            $html,
        );
        $this->assertStringNotContainsString('Почему рекомендация не подходит?', $html);
        $this->assertStringNotContainsString('Драма причины', $html);
    }

    public function test_feedback_subject_options_use_three_queries_and_cap_the_title_batch(): void
    {
        $titles = CatalogTitle::factory()->count(50)->create();
        $genre = Genre::query()->create(['name' => 'Batch жанр', 'slug' => 'batch-genre']);
        $country = Country::query()->create(['name' => 'Batch страна', 'slug' => 'batch-country']);
        $actor = Actor::query()->create(['name' => 'Batch актёр', 'slug' => 'batch-actor']);

        foreach ($titles as $title) {
            $title->genres()->attach($genre);
            $title->countries()->attach($country);
            $title->actors()->attach($actor);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CatalogRecommendationFeedbackOptionQuery::class)->forTitles($titles->take(1));
        $singleTitleQueries = DB::getQueryLog();

        DB::flushQueryLog();
        $options = app(CatalogRecommendationFeedbackOptionQuery::class)->forTitles($titles);
        $maximumBatchQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $singleTitleQueries);
        $this->assertCount(3, $maximumBatchQueries);
        $this->assertCount(48, $options);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recommendableTitle(string $title, array $attributes = []): CatalogTitle
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => $title,
            ...$attributes,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $catalogTitle;
    }

    /**
     * @param  array<string, array<string, int|float|string>>  $reasons
     */
    private function storeRecommendation(
        CatalogTitle $source,
        CatalogTitle $candidate,
        int $rank,
        int $score,
        array $reasons,
    ): void {
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'score' => $score,
            'rank' => $rank,
            'algorithm_version' => 'v3',
            'reasons' => $reasons,
            'computed_at' => now(),
        ]);
    }
}
