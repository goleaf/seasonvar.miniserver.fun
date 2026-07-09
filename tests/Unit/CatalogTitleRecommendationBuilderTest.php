<?php

namespace Tests\Unit;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTitleRecommendationBuilderTest extends TestCase
{
    use RefreshDatabase;

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

        $strongTitle = CatalogTitle::factory()->create([
            'title' => 'Сильное совпадение',
            'slug' => 'silnoe-sovpadenie',
            'year' => 2008,
        ]);
        $strongTitle->genres()->attach($genre->id);
        $strongTitle->actors()->attach($actor->id);
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

        app(CatalogTitleRecommendationBuilder::class)->rebuild();

        $recommendations = CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $sourceTitle->id)
            ->orderBy('rank')
            ->get();

        $this->assertCount(1, $recommendations);
        $this->assertSame($strongTitle->id, $recommendations->first()->recommended_title_id);
        $this->assertArrayHasKey('genre', $recommendations->first()->reasons);
        $this->assertArrayHasKey('actor', $recommendations->first()->reasons);
        $this->assertFalse(CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $sourceTitle->id)
            ->whereIn('recommended_title_id', [$countryOnlyTitle->id, $sameYearTitle->id, $withoutVideoTitle->id])
            ->exists());
    }
}
