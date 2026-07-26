<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogQualityIssueCategory;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use App\Services\Catalog\Quality\CatalogQualityQueueQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualityQueueQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function summary_and_page_query_count_do_not_grow_with_result_count(): void
    {
        $query = app(CatalogQualityQueueQuery::class);
        $this->createRows(1);

        DB::enableQueryLog();
        $query->summary();
        $query->paginate('all', '', null, null, 'score_asc', 25, 1);
        $singleCount = count(DB::getQueryLog());

        $this->createRows(20);
        DB::flushQueryLog();
        $query->summary();
        $page = $query->paginate('all', '', null, null, 'score_asc', 25, 1);
        $batchCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertCount(21, $page->items());
        self::assertLessThanOrEqual($singleCount + 1, $batchCount);
        self::assertLessThanOrEqual(7, $batchCount);
    }

    #[Test]
    public function each_named_queue_uses_current_issue_or_severity_state(): void
    {
        $query = app(CatalogQualityQueueQuery::class);

        foreach (CatalogQualityIssueCategory::cases() as $index => $category) {
            $title = CatalogTitle::factory()->create([
                'title' => 'Queue title '.$index,
            ]);
            CatalogTitleQualitySnapshot::factory()->for($title)->create();
            CatalogTitleQualityIssue::factory()->for($title)->create([
                'code' => 'queue_'.$category->value,
                'category' => $category,
            ]);

            $page = $query->paginate(
                $category->value,
                '',
                null,
                null,
                'score_asc',
                15,
                1,
            );

            self::assertCount(1, $page->items(), $category->value);
            self::assertSame($title->id, $page->items()[0]->catalogTitleId);
        }
    }

    #[Test]
    public function missing_video_queue_does_not_include_titles_with_only_stale_video_checks(): void
    {
        $missing = CatalogTitle::factory()->create();
        $stale = CatalogTitle::factory()->create();

        foreach ([$missing, $stale] as $title) {
            CatalogTitleQualitySnapshot::factory()->for($title)->create();
        }

        CatalogTitleQualityIssue::factory()->for($missing)->create([
            'code' => 'missing_video',
            'category' => CatalogQualityIssueCategory::MissingVideo,
        ]);
        CatalogTitleQualityIssue::factory()->for($stale)->create([
            'code' => 'stale_video_check',
            'category' => CatalogQualityIssueCategory::Stale,
        ]);

        $page = app(CatalogQualityQueueQuery::class)->paginate(
            CatalogQualityIssueCategory::MissingVideo->value,
            '',
            null,
            null,
            'score_asc',
            15,
            1,
        );

        self::assertSame([$missing->id], collect($page->items())->pluck('catalogTitleId')->all());
    }

    private function createRows(int $count): void
    {
        CatalogTitle::factory()
            ->count($count)
            ->create()
            ->each(function (CatalogTitle $title): void {
                CatalogTitleQualitySnapshot::factory()->for($title)->create();
                CatalogTitleQualityIssue::factory()->for($title)->create([
                    'category' => CatalogQualityIssueCategory::MissingPoster,
                ]);
            });
    }
}
