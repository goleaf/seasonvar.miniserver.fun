<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Services\Catalog\Quality\CatalogMetadataProvenanceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogMetadataProvenanceQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function provenance_for_a_full_quality_page_uses_a_constant_query_budget(): void
    {
        $titleIds = CatalogTitle::factory()->count(25)->create()->modelKeys();
        $query = app(CatalogMetadataProvenanceQuery::class);

        $singleTitleQueryCount = $this->queryCount(
            fn () => $query->forTitleIds([$titleIds[0]]),
        );
        $fullPageQueryCount = $this->queryCount(
            fn () => $query->forTitleIds($titleIds),
        );
        $rows = $query->forTitleIds($titleIds);

        self::assertCount(25, $rows);
        self::assertCount(8, $rows->get($titleIds[0], []));
        self::assertLessThanOrEqual($singleTitleQueryCount + 1, $fullPageQueryCount);
        self::assertLessThanOrEqual(20, $fullPageQueryCount);
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
