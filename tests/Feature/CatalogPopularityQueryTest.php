<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogPopularityQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogPopularityQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_popularity_signals_are_aggregated_once_instead_of_correlated_per_title(): void
    {
        $sql = app(CatalogPopularityQuery::class)->apply(CatalogTitle::query())->toSql();

        $this->assertGreaterThanOrEqual(4, substr_count(strtolower($sql), 'left join ('));
        $this->assertStringNotContainsString('where "catalog_title_id" = "catalog_titles"."id"', strtolower($sql));
        $this->assertStringContainsString('popularity_watchlist_count', $sql);
        $this->assertStringContainsString('popularity_watcher_count', $sql);
        $this->assertStringContainsString('popularity_review_count', $sql);
        $this->assertStringContainsString('popularity_provider_votes', $sql);
    }
}
