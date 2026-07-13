<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogTitlesCriteria;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogSearchQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_search_uses_filter_only_fts_while_result_search_materializes_ranking(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'План быстрого поиска']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);
        $request = CatalogTitlesRequest::create('/titles', 'GET', ['q' => 'быстрого поиска']);
        $request->setUserResolver(static fn () => null);
        $search = app(CatalogSearchQueryParser::class)->parse($request->normalizedSearch());
        $criteria = CatalogTitlesCriteria::fromRequest($request, $search, null, false);
        $titles = app(CatalogTitleQuery::class);

        $aggregate = $titles->filteredTitles($criteria, null);
        $ranked = $titles->filteredTitles($criteria, null, rankSearch: true);

        $aggregateSql = mb_strtolower($aggregate->toSql());
        $rankedSql = mb_strtolower($ranked->toSql());
        $this->assertStringContainsString(' in (select catalog_title_search_fts.rowid as catalog_title_id', $aggregateSql);
        $this->assertStringNotContainsString('bm25', $aggregateSql);
        $this->assertStringContainsString('from (select', $rankedSql);
        $this->assertStringContainsString('as "catalog_search_candidates" cross join "catalog_titles"', $rankedSql);
        $this->assertStringNotContainsString('from "catalog_titles" inner join (select', $rankedSql);
        $this->assertStringContainsString('bm25', $rankedSql);
        $this->assertStringContainsString('limit 9223372036854775807', $rankedSql);
        $this->assertSame([$title->id], $aggregate->pluck('catalog_titles.id')->all());
        $this->assertSame([$title->id], $ranked->pluck('catalog_titles.id')->all());

        $plan = DB::select('EXPLAIN QUERY PLAN '.$ranked->toSql(), $ranked->getBindings());
        $details = collect($plan)->pluck('detail')->implode("\n");
        $this->assertMatchesRegularExpression('/CO-ROUTINE catalog_search_candidates|MATERIALIZE catalog_search_candidates/', $details);
    }

    public function test_subtitle_context_counts_correlate_media_availability_to_filtered_titles(): void
    {
        $available = CatalogTitle::factory()->create(['title' => 'Быстрый поиск с субтитрами']);
        $missing = CatalogTitle::factory()->create(['title' => 'Быстрый поиск без субтитров']);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $available->id,
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => true,
        ]);
        app(CatalogSearchIndexer::class)->indexTitleIds([$available->id, $missing->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 2,
            'document_count' => 2,
            'completed_at' => now(),
        ]);
        $request = CatalogTitlesRequest::create('/titles', 'GET', ['q' => 'быстрый поиск']);
        $request->setUserResolver(static fn () => null);
        $search = app(CatalogSearchQueryParser::class)->parse($request->normalizedSearch());
        $criteria = CatalogTitlesCriteria::fromRequest($request, $search, null, false);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $counts = app(CatalogTitleQuery::class)->subtitleContextCounts($criteria, null);
            $executedSql = collect(DB::getQueryLog())
                ->pluck('query')
                ->map(fn (string $sql): string => mb_strtolower($sql))
                ->implode("\n");
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(['available' => 1, 'missing' => 1], $counts);
        $this->assertMatchesRegularExpression('/exists\s*\(select \* from "licensed_media"/', $executedSql);
        $this->assertStringNotContainsString('left join (select "catalog_title_id" from "licensed_media"', $executedSql);
    }

    public function test_search_page_paginator_counts_with_filter_only_fts(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Быстрый paginator поиска']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->get(route('titles.index', ['q' => 'paginator поиска']));
            $queries = collect(DB::getQueryLog())
                ->pluck('query')
                ->map(fn (string $sql): string => mb_strtolower($sql));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertOk()->assertSeeText($title->title);
        $paginatorCounts = $queries->filter(
            fn (string $sql): bool => str_contains($sql, 'count(*) as "aggregate"'),
        );

        $this->assertCount(1, $paginatorCounts);
        $this->assertStringContainsString('catalog_title_search_fts.rowid as catalog_title_id', $paginatorCounts->first());
        $this->assertStringNotContainsString('bm25', $paginatorCounts->first());
        $rankedQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'bm25'));
        $this->assertCount(1, $rankedQueries);
        $this->assertStringContainsString('select "catalog_titles"."id" from (select', $rankedQueries->first());
        $this->assertStringNotContainsString('select count(*) from "seasons"', $rankedQueries->first());

        $cardQuery = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "catalog_titles"')
            && str_contains($sql, 'where "catalog_titles"."id" in')
            && str_contains($sql, 'select count(*) from "seasons"'));
        $this->assertIsString($cardQuery);
        $this->assertStringNotContainsString('bm25', $cardQuery);
    }
}
