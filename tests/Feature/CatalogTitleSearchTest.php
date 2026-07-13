<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\Actor;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\Genre;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTitleSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranked_candidates_prioritize_exact_title_original_alias_and_weighted_fields(): void
    {
        $exactTitle = CatalogTitle::factory()->create([
            'title' => 'Северный ветер',
            'original_title' => null,
            'description' => null,
        ]);
        $originalTitle = CatalogTitle::factory()->create([
            'title' => 'Другой фильм',
            'original_title' => 'Северный ветер',
            'description' => null,
        ]);
        $aliasTitle = CatalogTitle::factory()->create([
            'title' => 'Третий фильм',
            'original_title' => null,
            'description' => null,
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $aliasTitle->id,
            'name' => 'Северный ветер',
            'name_hash' => hash('sha256', 'северный ветер'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        $personTitle = CatalogTitle::factory()->create([
            'title' => 'Фильм с актёром',
            'description' => null,
        ]);
        $actor = Actor::query()->create([
            'name' => 'Северный Ветер',
            'slug' => 'severnyi-veter-actor',
        ]);
        $personTitle->actors()->attach($actor);
        $taxonomyTitle = CatalogTitle::factory()->create([
            'title' => 'Фильм по жанру',
            'description' => null,
        ]);
        $genre = Genre::query()->create([
            'name' => 'Северный ветер',
            'slug' => 'severnyi-veter-genre',
        ]);
        $taxonomyTitle->genres()->attach($genre);
        $descriptionTitle = CatalogTitle::factory()->create([
            'title' => 'Фильм по описанию',
            'description' => 'Только здесь встречается северный ветер.',
        ]);
        $ids = collect([
            $exactTitle,
            $originalTitle,
            $aliasTitle,
            $personTitle,
            $taxonomyTitle,
            $descriptionTitle,
        ])->pluck('id');
        app(CatalogSearchIndexer::class)->indexTitleIds($ids);
        $this->markReady($ids->count());
        $query = app(CatalogSearchQueryParser::class)->parse('Северный ветер');

        $rankedIds = app(CatalogTitleSearch::class)
            ->candidateQuery($query)?->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertSame([
            $exactTitle->id,
            $originalTitle->id,
            $aliasTitle->id,
            $personTitle->id,
            $taxonomyTitle->id,
            $descriptionTitle->id,
        ], $rankedIds);
    }

    public function test_ready_index_matches_transliteration_short_and_punctuation_queries(): void
    {
        $znakhar = CatalogTitle::factory()->create(['title' => 'Знахарь']);
        $oa = CatalogTitle::factory()->create(['title' => 'OA']);
        $numbers = CatalogTitle::factory()->create(['title' => '11/22/63']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$znakhar->id, $oa->id, $numbers->id]);
        $this->markReady(3);
        $parser = app(CatalogSearchQueryParser::class);
        $search = app(CatalogTitleSearch::class);

        $this->assertSame([$znakhar->id], $search->candidateQuery($parser->parse('znakhar'))?->pluck('catalog_title_id')->all());
        $this->assertSame([$oa->id], $search->candidateQuery($parser->parse('OA'))?->pluck('catalog_title_id')->all());
        $this->assertSame([$numbers->id], $search->candidateQuery($parser->parse('11.22.63'))?->pluck('catalog_title_id')->all());
    }

    public function test_non_ready_or_version_mismatched_state_returns_null_for_legacy_fallback(): void
    {
        $query = app(CatalogSearchQueryParser::class)->parse('Знахарь');
        $search = app(CatalogTitleSearch::class);

        foreach ([
            CatalogSearchIndexStatus::Building,
            CatalogSearchIndexStatus::Stale,
            CatalogSearchIndexStatus::Failed,
        ] as $status) {
            CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
                'version' => CatalogSearchIndexer::INDEX_VERSION,
                'status' => $status,
            ]);

            $this->assertNull($search->candidateQuery($query));
            $this->assertNull($search->matchingTitleIdsQuery($query));
            $search->forgetState();
        }

        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION + 1,
            'status' => CatalogSearchIndexStatus::Ready,
        ]);
        $search->forgetState();

        $this->assertNull($search->candidateQuery($query));
        $this->assertNull($search->matchingTitleIdsQuery($query));
    }

    public function test_filter_only_matching_avoids_rank_columns_and_ranked_candidates_keep_materialization_boundary(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'План полнотекстового поиска']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        $this->markReady(1);
        $query = app(CatalogSearchQueryParser::class)->parse('полнотекстового поиска');
        $search = app(CatalogTitleSearch::class);

        $this->assertTrue(
            method_exists($search, 'matchingTitleIdsQuery'),
            'CatalogTitleSearch should expose a filter-only FTS query.',
        );

        $matching = $search->matchingTitleIdsQuery($query);
        $ranked = $search->candidateQuery($query);

        $this->assertNotNull($matching);
        $this->assertNotNull($ranked);
        $this->assertSame([$title->id], $matching->pluck('catalog_title_id')->all());

        $matchingSql = mb_strtolower($matching->toSql());
        $this->assertStringContainsString('catalog_title_search_fts.rowid as catalog_title_id', $matchingSql);
        $this->assertStringContainsString('catalog_title_search_fts match ?', $matchingSql);
        $this->assertStringNotContainsString('catalog_title_search_documents', $matchingSql);
        $this->assertStringNotContainsString('bm25', $matchingSql);
        $this->assertStringNotContainsString('order by', $matchingSql);

        $rankedSql = mb_strtolower($ranked->toSql());
        $this->assertStringContainsString('bm25', $rankedSql);
        $this->assertStringContainsString('limit 9223372036854775807', $rankedSql);
    }

    public function test_candidate_subquery_uses_the_fts_virtual_table_plan_without_php_id_materialization(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'План полнотекстового поиска']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        $this->markReady(1);
        $query = app(CatalogTitleSearch::class)->candidateQuery(
            app(CatalogSearchQueryParser::class)->parse('полнотекстового поиска'),
        );

        $this->assertNotNull($query);
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());
        $details = collect($plan)->pluck('detail')->implode("\n");

        $this->assertStringContainsString('VIRTUAL TABLE INDEX', $details);
        $this->assertStringContainsString('catalog_title_search_documents', $query->toSql());
    }

    private function markReady(int $count): void
    {
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => $count,
            'document_count' => $count,
            'completed_at' => now(),
        ]);
    }
}
