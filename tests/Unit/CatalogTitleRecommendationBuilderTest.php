<?php

namespace Tests\Unit;

use App\Enums\ContentAudience;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTitleRecommendationBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_caps_profile_hydration_chunk_size_to_the_measured_memory_budget(): void
    {
        config(['seasonvar.recommendations.chunk_size' => 500]);
        $started = null;

        app(CatalogTitleRecommendationBuilder::class)->rebuild(
            function (string $event, array $context) use (&$started): void {
                if ($event === 'catalog-title-recommendations-started') {
                    $started = $context;
                }
            },
        );

        $this->assertIsArray($started);
        $this->assertSame(100, $started['chunk_size']);
    }

    public function test_rebuild_scores_shared_metadata_and_filters_weak_candidates(): void
    {
        config([
            'seasonvar.recommendations.min_score' => 600,
        ]);

        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $country = Country::query()->create([
            'name' => 'Испания',
            'slug' => 'ispaniia',
        ]);
        $actor = Actor::query()->create([
            'name' => 'Иван Петров',
            'slug' => 'ivan-petrov',
        ]);
        $sourceTitle = CatalogTitle::factory()->create([
            'title' => 'Главный сериал',
            'slug' => 'glavnyi-serial',
            'year' => 2007,
        ]);
        $sourceTitle->genres()->attach($genre->id);
        $sourceTitle->countries()->attach($country->id);
        $sourceTitle->actors()->attach($actor->id);
        CatalogTitleRecommendationSignal::query()->create([
            'catalog_title_id' => $sourceTitle->id,
            'source' => 'seasonvar_info',
            'signal_type' => 'taxonomy_genre',
            'signal_key' => 'detektiv',
            'signal_value' => 'Детектив',
            'weight' => 120,
            'observed_at' => now(),
        ]);

        $strongTitle = CatalogTitle::factory()->create([
            'title' => 'Сильное совпадение',
            'slug' => 'silnoe-sovpadenie',
            'year' => 2008,
        ]);
        $strongTitle->genres()->attach($genre->id);
        $strongTitle->actors()->attach($actor->id);
        CatalogTitleRecommendationSignal::query()->create([
            'catalog_title_id' => $strongTitle->id,
            'source' => 'seasonvar_info',
            'signal_type' => 'taxonomy_genre',
            'signal_key' => 'detektiv',
            'signal_value' => 'Детектив',
            'weight' => 120,
            'observed_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $strongTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $countryOnlyTitle = CatalogTitle::factory()->create([
            'title' => 'Только страна',
            'slug' => 'tolko-strana',
            'year' => 2015,
        ]);
        $countryOnlyTitle->countries()->attach($country->id);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $countryOnlyTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $sameYearTitle = CatalogTitle::factory()->create([
            'title' => 'Только год',
            'slug' => 'tolko-god',
            'year' => 2007,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $sameYearTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $withoutVideoTitle = CatalogTitle::factory()->create([
            'title' => 'Без видео',
            'slug' => 'bez-video',
            'year' => 2007,
        ]);
        $withoutVideoTitle->genres()->attach($genre->id);

        $result = app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $recommendations = CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $sourceTitle->id)
            ->orderBy('rank')
            ->get();

        $this->assertSame('full', $result['mode']);
        $this->assertSame('v2', $result['algorithm_version']);
        $this->assertSame(5, $result['titles']);
        $this->assertSame(2, $result['titles_with_recommendations']);
        $this->assertSame(3, $result['titles_without_recommendations']);
        $this->assertSame(2, $result['stored']);
        $this->assertSame(0.4, $result['average_recommendations']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
        $this->assertCount(1, $recommendations);
        $this->assertSame($strongTitle->id, $recommendations->first()->recommended_title_id);
        $this->assertArrayHasKey('genre', $recommendations->first()->reasons);
        $this->assertArrayHasKey('actor', $recommendations->first()->reasons);
        $this->assertArrayHasKey('source_signal', $recommendations->first()->reasons);
        $this->assertGreaterThan(0, $recommendations->first()->matched_features_count);
        $this->assertGreaterThan(0, $recommendations->first()->metadata_score);
        $this->assertGreaterThan(0, $recommendations->first()->source_score);
        $this->assertGreaterThan(0, $recommendations->first()->quality_score);
        $this->assertSame($recommendations->first()->score, array_sum([
            $recommendations->first()->metadata_score,
            $recommendations->first()->source_score,
            $recommendations->first()->quality_score,
        ]));
        $this->assertSame([
            'metadata' => $recommendations->first()->metadata_score,
            'source' => $recommendations->first()->source_score,
            'quality' => $recommendations->first()->quality_score,
            'total' => $recommendations->first()->score,
        ], $recommendations->first()->scoreBreakdown());
        $this->assertContains('Жанр', $recommendations->first()->reasonLabels());
        $this->assertContains('Актеры', $recommendations->first()->reasonLabels());
        $this->assertContains('Источник', $recommendations->first()->reasonLabels());
        $this->assertFalse(CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $sourceTitle->id)
            ->whereIn('recommended_title_id', [$countryOnlyTitle->id, $sameYearTitle->id, $withoutVideoTitle->id])
            ->exists());
    }

    public function test_rebuild_limits_stored_recommendations_per_title(): void
    {
        config([
            'seasonvar.recommendations.min_score' => 1,
            'seasonvar.recommendations.max_per_title' => 3,
        ]);

        $genre = Genre::query()->create([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        $sourceTitle = CatalogTitle::factory()->create([
            'title' => 'Главная драма',
            'slug' => 'glavnaia-drama',
            'year' => 2020,
        ]);
        $sourceTitle->genres()->attach($genre->id);

        collect(range(1, 5))->each(function (int $number) use ($genre): void {
            $title = CatalogTitle::factory()->create([
                'title' => 'Похожая драма '.$number,
                'slug' => 'poxozhaia-drama-'.$number,
                'year' => 2020,
            ]);
            $title->genres()->attach($genre->id);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'status' => 'published',
                'published_at' => now(),
            ]);
        });

        $result = app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $sourceRecommendations = CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $sourceTitle->id)
            ->orderBy('rank')
            ->get();
        $largestStoredSet = CatalogTitleRecommendation::query()
            ->selectRaw('catalog_title_id, count(*) as recommendations_count')
            ->groupBy('catalog_title_id')
            ->orderByDesc('recommendations_count')
            ->value('recommendations_count');

        $this->assertSame(3, $result['max_per_title']);
        $this->assertSame([1, 2, 3], $sourceRecommendations->pluck('rank')->all());
        $this->assertCount(3, $sourceRecommendations);
        $this->assertLessThanOrEqual(3, (int) $largestStoredSet);
    }

    public function test_rebuild_uses_the_public_catalog_visibility_boundary(): void
    {
        config([
            'seasonvar.recommendations.min_score' => 1,
        ]);

        $genre = Genre::query()->create([
            'name' => 'Фантастика',
            'slug' => 'fantastika',
        ]);
        $source = CatalogTitle::factory()->create([
            'title' => 'Публичный источник',
            'slug' => 'publichnyi-istochnik',
        ]);
        $source->genres()->attach($genre);
        $publicCandidate = CatalogTitle::factory()->create([
            'title' => 'Публичный кандидат',
            'slug' => 'publichnyi-kandidat',
        ]);
        $publicCandidate->genres()->attach($genre);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $publicCandidate->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $memberCandidate = CatalogTitle::factory()->create([
            'title' => 'Кандидат для участника',
            'slug' => 'kandidat-dlia-uchastnika',
            'audience' => ContentAudience::Authenticated,
        ]);
        $memberCandidate->genres()->attach($genre);

        $result = app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $this->assertSame(2, $result['titles']);
        $this->assertDatabaseHas('catalog_title_recommendations', [
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $publicCandidate->id,
        ]);
        $this->assertDatabaseMissing('catalog_title_recommendations', [
            'recommended_title_id' => $memberCandidate->id,
        ]);
    }

    public function test_profile_index_uses_compact_serialized_payloads_and_packed_feature_ids(): void
    {
        $genre = Genre::query()->create([
            'name' => 'Компактный жанр',
            'slug' => 'kompaktnyi-zhanr',
        ]);
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $singletonActor = Actor::query()->create([
            'name' => 'Уникальный актёр',
            'slug' => 'unikalnyi-akter',
        ]);
        $first->genres()->attach($genre->id);
        $second->genres()->attach($genre->id);
        $first->actors()->attach($singletonActor->id);
        $method = new \ReflectionMethod(CatalogTitleRecommendationBuilder::class, 'compactProfileIndex');

        $builder = app(CatalogTitleRecommendationBuilder::class);
        $profiles = $method->invoke($builder, 10);
        $decode = new \ReflectionMethod(CatalogTitleRecommendationBuilder::class, 'decodeProfile');
        $candidateIds = new \ReflectionMethod(CatalogTitleRecommendationBuilder::class, 'candidateIds');

        $this->assertIsString($profiles[$first->id]);
        $this->assertIsString($profiles[$second->id]);
        $this->assertSame(
            [$second->id],
            $candidateIds->invoke($builder, $decode->invoke($builder, $profiles[$first->id])),
        );
    }

    public function test_candidate_buffer_keeps_only_the_best_rows_per_diversity_key(): void
    {
        $builder = app(CatalogTitleRecommendationBuilder::class);
        $retain = new \ReflectionMethod(CatalogTitleRecommendationBuilder::class, 'retainDiversityCandidate');
        $rows = [];

        foreach (range(1, 10) as $score) {
            $arguments = [&$rows, [
                'score' => $score,
                'diversity_key' => 'genre:1',
            ], 3];
            $retain->invokeArgs($builder, $arguments);
        }

        $this->assertCount(3, $rows['genre:1']);
        $this->assertSame([10, 9, 8], collect($rows['genre:1'])->sortByDesc('score')->pluck('score')->values()->all());
    }

    public function test_candidate_selection_is_hard_bounded_before_exact_scoring(): void
    {
        config([
            'seasonvar.recommendations.candidate_limit' => 2,
            'seasonvar.recommendations.candidate_scan_per_feature' => 10,
        ]);

        $genre = Genre::query()->create([
            'name' => 'Ограниченный жанр',
            'slug' => 'ogranichennyi-zhanr',
        ]);
        $source = CatalogTitle::factory()->create();
        $source->genres()->attach($genre);

        collect(range(1, 5))->each(function () use ($genre): void {
            $candidate = CatalogTitle::factory()->create();
            $candidate->genres()->attach($genre);
        });

        $builder = app(CatalogTitleRecommendationBuilder::class);
        $profilesMethod = new \ReflectionMethod($builder, 'compactProfileIndex');
        $decodeMethod = new \ReflectionMethod($builder, 'decodeProfile');
        $candidateMethod = new \ReflectionMethod($builder, 'candidateIds');
        $profiles = $profilesMethod->invoke($builder, 10);
        $sourceProfile = $decodeMethod->invoke($builder, $profiles[$source->id]);

        $this->assertCount(2, $candidateMethod->invoke($builder, $sourceProfile));
    }
}
