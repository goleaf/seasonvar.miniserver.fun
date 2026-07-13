<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationListItem;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogTitlePageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'theme_romance' => ['score' => 360],
            'country' => ['count' => 1, 'score' => 110],
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
        $this->assertSame(['Романтика', 'Страна'], $items->first()->reasonLabels);
        $this->assertSame(920, $items->first()->score);
        $this->assertArrayNotHasKey('recommendedTitleRecommendations', $data);
        $this->assertArrayNotHasKey('genreRecommendations', $data);
        $this->assertArrayNotHasKey('yearRecommendations', $data);
    }

    public function test_page_builder_merges_and_deduplicates_genre_and_year_fallbacks(): void
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

        $items = app(CatalogTitlePageBuilder::class)->data($source, null)['recommendationItems'];

        $this->assertSame([$both->id, $genreOnly->id, $yearOnly->id], $items->pluck('title.id')->all());
        $this->assertSame([1, 2, 3], $items->pluck('rank')->all());
        $this->assertSame(['Похожий жанр', 'Тот же год'], $items->first()->reasonLabels);
        $this->assertSame(['Похожий жанр'], $items->get(1)->reasonLabels);
        $this->assertSame(['Тот же год'], $items->get(2)->reasonLabels);
        $this->assertNull($items->first()->score);
    }

    public function test_title_page_renders_one_ranked_recommendation_list_with_wide_posters(): void
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
        $this->assertStringContainsString('aspect-[16/10]', $html);
        $this->assertStringContainsString('Романтика', $html);
        $this->assertStringContainsString('Легкая история любви', $html);
        $this->assertStringContainsString('data-recommendation-rank="1"', $html);
        $this->assertStringContainsString('data-recommendation-rank="2"', $html);
        $this->assertLessThan(strpos($html, 'Второй точный совет'), strpos($html, 'Первый точный совет'));
        $this->assertStringNotContainsString('Ближайшие совпадения', $html);
        $this->assertStringNotContainsString('По похожим жанрам', $html);
        $this->assertStringNotContainsString('За '.$source->year.' год', $html);
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
