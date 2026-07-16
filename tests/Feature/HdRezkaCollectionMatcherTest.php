<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\HdRezkaCollectionItemData;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleSearchDocument;
use App\Models\Country;
use App\Models\Genre;
use App\Services\Catalog\Search\CatalogSearchDocumentBuilder;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Collections\Import\HdRezkaCollectionMatcher;
use App\Support\CatalogTitleDisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HdRezkaCollectionMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_primary_title_and_year_match_one_local_title(): void
    {
        $title = $this->indexedTitle([
            'title' => 'Муфаса: Король Лев',
            'year' => 2024,
            'type' => 'cartoon',
        ]);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Муфаса: Король Лев',
            year: 2024,
            type: 'cartoon',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
        $this->assertSame($title->id, $match->catalogTitleId);
        $this->assertSame('primary', $match->method);
        $this->assertGreaterThanOrEqual(130, $match->confidence);
        $this->assertSame('primary', $match->reasons['name']);
    }

    public function test_original_title_can_supply_the_exact_name_evidence(): void
    {
        $title = $this->indexedTitle([
            'title' => 'Король саванны',
            'original_title' => 'Mufasa: The Lion King',
            'year' => 2024,
            'type' => 'film',
        ]);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Mufasa: The Lion King',
            year: 2024,
            type: 'film',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
        $this->assertSame($title->id, $match->catalogTitleId);
        $this->assertSame('original', $match->method);
    }

    public function test_alias_can_supply_the_exact_name_evidence(): void
    {
        $title = $this->indexedTitle([
            'title' => 'Король саванны',
            'year' => 2024,
            'type' => 'film',
        ], aliases: ['Mufasa']);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Mufasa',
            year: 2024,
            type: 'film',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
        $this->assertSame($title->id, $match->catalogTitleId);
        $this->assertSame('alias', $match->method);
    }

    public function test_explicit_year_mismatch_is_never_attached(): void
    {
        $this->indexedTitle(['title' => 'Одинаковое имя', 'year' => 2023, 'type' => 'film']);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Одинаковое имя',
            year: 2024,
            type: 'film',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Unmatched, $match->status);
        $this->assertNull($match->catalogTitleId);
        $this->assertSame('no_eligible_candidate', $match->method);
    }

    public function test_explicit_type_mismatch_is_never_attached(): void
    {
        $this->indexedTitle(['title' => 'Одинаковое имя', 'year' => 2024, 'type' => 'anime']);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Одинаковое имя',
            year: 2024,
            type: 'series',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Unmatched, $match->status);
        $this->assertNull($match->catalogTitleId);
    }

    public function test_two_country_overlaps_break_an_otherwise_equal_tie(): void
    {
        $matching = $this->indexedTitle(
            ['title' => 'Общее имя', 'year' => 2024, 'type' => 'film'],
            countries: ['США', 'Канада'],
        );
        $this->indexedTitle(
            ['title' => 'Общее имя', 'year' => 2024, 'type' => 'film'],
            countries: ['Италия'],
        );

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Общее имя',
            year: 2024,
            type: 'film',
            countries: ['сша', 'канада'],
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
        $this->assertSame($matching->id, $match->catalogTitleId);
        $this->assertSame(20, $match->reasons['country_score']);
    }

    public function test_equal_candidates_remain_ambiguous(): void
    {
        $this->indexedTitle(['title' => 'Общее имя', 'year' => 2024, 'type' => 'film']);
        $this->indexedTitle(['title' => 'Общее имя', 'year' => 2024, 'type' => 'film']);

        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Общее имя',
            year: 2024,
            type: 'film',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Ambiguous, $match->status);
        $this->assertNull($match->catalogTitleId);
        $this->assertSame('insufficient_lead', $match->method);
    }

    public function test_detail_original_title_and_genres_can_resolve_a_tie(): void
    {
        $matching = $this->indexedTitle(
            [
                'title' => 'Общее имя',
                'original_title' => 'The Exact Original',
                'year' => 2024,
                'type' => 'film',
            ],
            genres: ['Драма', 'Приключения', 'Семейный'],
        );
        $this->indexedTitle(
            [
                'title' => 'Общее имя',
                'original_title' => 'Another Original',
                'year' => 2024,
                'type' => 'film',
            ],
            genres: ['Комедия'],
        );

        $match = app(HdRezkaCollectionMatcher::class)->match(
            $this->item(title: 'Общее имя', year: 2024, type: 'film'),
            [
                'original_title' => 'The Exact Original',
                'year' => 2024,
                'type' => 'film',
                'genres' => ['драма', 'приключения', 'семейный'],
            ],
        );

        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
        $this->assertSame($matching->id, $match->catalogTitleId);
        $this->assertSame(25, $match->reasons['detail_original_score']);
        $this->assertSame(15, $match->reasons['genre_score']);
    }

    public function test_no_exact_candidate_is_unmatched(): void
    {
        $match = app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Такого тайтла нет',
            year: 2024,
            type: 'film',
        ));

        $this->assertSame(CatalogCollectionSourceMatchStatus::Unmatched, $match->status);
        $this->assertNull($match->catalogTitleId);
        $this->assertSame('no_exact_candidate', $match->method);
        $this->assertSame(0, $match->confidence);
    }

    public function test_match_query_count_is_bounded_independently_of_catalog_size(): void
    {
        $this->indexedTitle(['title' => 'Искомый тайтл', 'year' => 2024, 'type' => 'film']);

        foreach (range(1, 15) as $index) {
            $this->indexedTitle([
                'title' => "Посторонний тайтл {$index}",
                'year' => 2024,
                'type' => 'film',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(HdRezkaCollectionMatcher::class)->match($this->item(
            title: 'Искомый тайтл',
            year: 2024,
            type: 'film',
        ));

        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $aliases
     * @param  list<string>  $countries
     * @param  list<string>  $genres
     */
    private function indexedTitle(
        array $attributes,
        array $aliases = [],
        array $countries = [],
        array $genres = [],
    ): CatalogTitle {
        $title = CatalogTitle::factory()->create($attributes);

        foreach ($aliases as $alias) {
            $title->aliases()->create([
                'name' => $alias,
                'name_hash' => CatalogTitleDisplayName::nameHash($alias),
                'type' => 'alternative',
                'source' => 'test',
            ]);
        }

        foreach ($countries as $countryName) {
            $country = Country::query()->firstOrCreate(
                ['slug' => Str::slug($countryName).'-'.substr(hash('sha256', $countryName), 0, 8)],
                ['name' => $countryName],
            );
            $title->countries()->syncWithoutDetaching([$country->id]);
        }

        foreach ($genres as $genreName) {
            $genre = Genre::query()->firstOrCreate(
                ['slug' => Str::slug($genreName).'-'.substr(hash('sha256', $genreName), 0, 8)],
                ['name' => $genreName],
            );
            $title->genres()->syncWithoutDetaching([$genre->id]);
        }

        $title->load('aliases');
        CatalogTitleSearchDocument::query()->updateOrCreate(
            ['catalog_title_id' => $title->id],
            app(CatalogSearchDocumentBuilder::class)->build($title),
        );

        return $title;
    }

    /** @param list<string> $countries */
    private function item(
        string $title,
        ?int $year,
        ?string $type,
        array $countries = [],
    ): HdRezkaCollectionItemData {
        return new HdRezkaCollectionItemData(
            sourceItemKey: '668',
            title: $title,
            normalizedTitleKey: app(CatalogSearchNormalizer::class)->key($title),
            year: $year,
            type: $type,
            countries: $countries,
            detailPath: '/668-title.html',
            page: 1,
            position: 1,
        );
    }
}
