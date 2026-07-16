<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSuggestionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitleSuggestionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_and_bounds_public_title_name_matches_without_searching_descriptions(): void
    {
        $exact = CatalogTitle::factory()->create([
            'title' => 'Север',
            'slug' => 'sever',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Северный ветер',
            'slug' => 'severnyi-veter',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Далекий Север',
            'slug' => 'dalekii-sever',
        ]);

        foreach (range(1, 5) as $index) {
            CatalogTitle::factory()->create([
                'title' => "История про Север {$index}",
                'slug' => "istoriia-pro-sever-{$index}",
            ]);
        }

        $descriptionOnly = CatalogTitle::factory()->create([
            'title' => 'Южная история',
            'slug' => 'iuzhnaia-istoriia',
            'description' => 'Север упоминается только в описании.',
        ]);
        $hidden = CatalogTitle::factory()->create([
            'title' => 'Север закрытый',
            'slug' => 'sever-zakrytyi',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $query = app(CatalogSearchQueryParser::class)->parse('  Север  ');
        $results = app(CatalogTitleSuggestionQuery::class)->search($query, null, 5);

        $this->assertCount(5, $results);
        $this->assertSame($exact->id, $results->first()?->id);
        $this->assertNotContains($descriptionOnly->id, $results->pluck('id')->all());
        $this->assertNotContains($hidden->id, $results->pluck('id')->all());
    }

    public function test_it_loads_rich_public_card_fields_and_counts_available_releases_in_a_bounded_query_budget(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Полярный сериал',
            'slug' => 'poliarnyi-serial',
            'poster_url' => 'https://images.example.com/polar.jpg',
            'year' => 2024,
        ]);
        $publicSeason = Season::factory()->for($title)->create(['number' => 1]);
        Episode::factory()->count(2)->for($publicSeason)->create();
        $hiddenSeason = Season::factory()->for($title)->create([
            'number' => 2,
            'publication_status' => PublicationStatus::Hidden,
        ]);
        Episode::factory()->for($hiddenSeason)->create();
        $membersSeason = Season::factory()->for($title)->create([
            'number' => 3,
            'audience' => ContentAudience::Authenticated,
        ]);
        Episode::factory()->for($membersSeason)->create();
        Episode::factory()->for($publicSeason)->create([
            'number' => 99,
            'publication_status' => PublicationStatus::Hidden,
        ]);

        DB::enableQueryLog();

        $result = app(CatalogTitleSuggestionQuery::class)
            ->search(app(CatalogSearchQueryParser::class)->parse('Полярный'), null, 5)
            ->sole();

        $this->assertSame('https://images.example.com/polar.jpg', $result->poster_url);
        $this->assertSame(2024, $result->year);
        $this->assertSame(1, (int) $result->getAttribute('seasons_count'));
        $this->assertSame(2, (int) $result->getAttribute('episodes_count'));
        $this->assertLessThanOrEqual(7, count(DB::getQueryLog()));

        $episodeAggregateSql = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $sql): bool => str_contains($sql, 'from "episodes"')) ?? '';

        $this->assertStringContainsString('"season_id" in', $episodeAggregateSql);
        $this->assertStringNotContainsString('join "seasons"', $episodeAggregateSql);
    }
}
