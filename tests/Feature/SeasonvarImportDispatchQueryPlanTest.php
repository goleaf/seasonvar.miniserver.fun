<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarRefreshPlanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class SeasonvarImportDispatchQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_dispatch_schema_exposes_progress_outbox_and_unique_ledger_contracts(): void
    {
        $this->assertTrue(Schema::hasColumns('seasonvar_import_runs', [
            'last_progress_at',
        ]));
        $this->assertTrue(Schema::hasColumns('seasonvar_import_prepared_pages', [
            'last_enqueue_attempt_at',
            'enqueue_attempts',
        ]));

        $runIndexes = collect(Schema::getIndexes('seasonvar_import_runs'))
            ->keyBy('name');
        $preparedIndexes = collect(Schema::getIndexes(
            'seasonvar_import_prepared_pages',
        ))->keyBy('name');

        $this->assertSame(
            ['last_progress_at'],
            $runIndexes->get('seasonvar_import_runs_last_progress_at_index')['columns'] ?? null,
        );
        $this->assertSame(
            ['seasonvar_import_run_id', 'source_page_id'],
            $preparedIndexes->get('seasonvar_prepared_run_source_unique')['columns'] ?? null,
        );
        $this->assertTrue(
            $preparedIndexes->get('seasonvar_prepared_run_source_unique')['unique'] ?? false,
        );
        $this->assertSame(
            [
                'seasonvar_import_run_id',
                'status',
                'last_enqueue_attempt_at',
                'id',
            ],
            $preparedIndexes->get('seasonvar_prepared_outbox_due_idx')['columns'] ?? null,
        );
    }

    public function test_batch_dispatch_progress_and_outbox_attributes_are_typed(): void
    {
        $run = new SeasonvarImportRun([
            'last_progress_at' => now()->toIso8601String(),
        ]);
        $preparedPage = new SeasonvarImportPreparedPage([
            'last_enqueue_attempt_at' => now()->toIso8601String(),
        ]);

        $this->assertInstanceOf(Carbon::class, $run->last_progress_at);
        $this->assertInstanceOf(
            Carbon::class,
            $preparedPage->last_enqueue_attempt_at,
        );
        $this->assertSame(0, $preparedPage->enqueue_attempts);
    }

    public function test_due_outbox_query_uses_the_batch_dispatch_index(): void
    {
        $preparedPage = $this->preparedPage();
        $cutoff = now()->subMinute();
        $query = SeasonvarImportPreparedPage::query()
            ->select([
                'id',
                'seasonvar_import_title_group_id',
            ])
            ->where(
                'seasonvar_import_run_id',
                $preparedPage->seasonvar_import_run_id,
            )
            ->where('status', SeasonvarPreparedPageStatus::Queued->value)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('last_enqueue_attempt_at')
                    ->orWhere('last_enqueue_attempt_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit(101);

        $plan = DB::select(
            'EXPLAIN QUERY PLAN '.$query->toSql(),
            $query->getBindings(),
        );
        $details = collect($plan)
            ->map(static fn (object $row): string => (string) $row->detail)
            ->implode(' ');

        $this->assertStringContainsString(
            'seasonvar_prepared_outbox_due_idx',
            $details,
        );
    }

    public function test_migration_fails_closed_when_run_page_duplicates_exist(): void
    {
        $run = $this->importRun();
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
        ]);
        $firstGroup = $this->group($run, 'duplicate-first');
        $secondGroup = $this->group($run, 'duplicate-second');
        $migration = require database_path(
            'migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php',
        );

        $migration->down();
        $now = now();
        DB::table('seasonvar_import_prepared_pages')->insert([
            [
                'seasonvar_import_run_id' => $run->id,
                'seasonvar_import_title_group_id' => $firstGroup->id,
                'source_page_id' => $page->id,
                'status' => SeasonvarPreparedPageStatus::Queued->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'seasonvar_import_run_id' => $run->id,
                'seasonvar_import_title_group_id' => $secondGroup->id,
                'source_page_id' => $page->id,
                'status' => SeasonvarPreparedPageStatus::Queued->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(
                'Нельзя включить unique run/page ledger',
            );

            $migration->up();
        } finally {
            $duplicateId = DB::table('seasonvar_import_prepared_pages')
                ->where('seasonvar_import_run_id', $run->id)
                ->where('source_page_id', $page->id)
                ->orderByDesc('id')
                ->value('id');

            if (is_numeric($duplicateId)) {
                DB::table('seasonvar_import_prepared_pages')
                    ->where('id', (int) $duplicateId)
                    ->delete();
            }

            $migration->up();
        }
    }

    public function test_migration_rollback_preserves_rows_and_recovery_index(): void
    {
        $preparedPage = $this->preparedPage();
        $migration = require database_path(
            'migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php',
        );

        try {
            $migration->down();
            $indexNames = collect(Schema::getIndexes(
                'seasonvar_import_prepared_pages',
            ))->pluck('name');

            $this->assertDatabaseHas('seasonvar_import_prepared_pages', [
                'id' => $preparedPage->id,
            ]);
            $this->assertTrue($indexNames->contains(
                'seasonvar_prepared_run_status_updated_id_idx',
            ));
            $this->assertFalse($indexNames->contains(
                'seasonvar_prepared_run_source_unique',
            ));
            $this->assertFalse($indexNames->contains(
                'seasonvar_prepared_outbox_due_idx',
            ));
            $this->assertFalse(Schema::hasColumn(
                'seasonvar_import_runs',
                'last_progress_at',
            ));
            $this->assertFalse(Schema::hasColumns(
                'seasonvar_import_prepared_pages',
                [
                    'last_enqueue_attempt_at',
                    'enqueue_attempts',
                ],
            ));
        } finally {
            $migration->up();
        }
    }

    public function test_planner_excludes_a_prepared_page_only_for_the_exact_run(): void
    {
        $firstRun = $this->importRun();
        $secondRun = $this->importRun();
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);
        $group = $this->group($firstRun, 'planner-ledger');
        SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $firstRun->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);

        $this->assertNotContains(
            $page->id,
            $this->forcedPlannerIds($firstRun->id),
        );
        $this->assertContains(
            $page->id,
            $this->forcedPlannerIds($secondRun->id),
        );
    }

    public function test_overlapping_planner_reasons_do_not_duplicate_or_skip_resume_state(): void
    {
        $run = $this->importRun();
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'missing_data',
            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'last_imported_at' => now()->subDays(2),
            'retry_after_at' => null,
        ]);

        $this->assertSame(
            [$page->id],
            $this->automaticPlannerIds($run->id),
        );

        $group = $this->group($run, 'planner-overlap');
        SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);

        $this->assertSame([], $this->automaticPlannerIds($run->id));
    }

    public function test_persisted_sitemap_tail_ids_keep_order_and_skip_the_exact_ledger_row(): void
    {
        $run = $this->importRun();
        $pages = SourcePage::factory()->count(3)->create([
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);
        $orderedIds = [
            (int) $pages[2]->id,
            (int) $pages[0]->id,
            (int) $pages[1]->id,
        ];
        $group = $this->group($run, 'persisted-tail');
        SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $pages[1]->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);
        $selectedIds = [];

        foreach (app(SeasonvarRefreshPlanner::class)->forcedPageChunksForIds(
            $orderedIds,
            2,
            $run->id,
        ) as $selectedPages) {
            array_push(
                $selectedIds,
                ...$selectedPages->pluck('id')->map(
                    static fn (mixed $id): int => (int) $id,
                )->all(),
            );
        }

        $this->assertSame([
            $pages[2]->id,
            $pages[0]->id,
        ], $selectedIds);
    }

    public function test_planner_anti_join_uses_the_unique_run_page_ledger_index(): void
    {
        $preparedPage = $this->preparedPage();
        $query = SourcePage::query()
            ->where('page_type', 'serial')
            ->whereNotIn(
                (new SourcePage)->qualifyColumn('id'),
                SeasonvarImportPreparedPage::query()
                    ->select('source_page_id')
                    ->where(
                        'seasonvar_import_run_id',
                        $preparedPage->seasonvar_import_run_id,
                    ),
            )
            ->orderBy('id');

        $plan = DB::select(
            'EXPLAIN QUERY PLAN '.$query->toSql(),
            $query->getBindings(),
        );
        $details = collect($plan)
            ->map(static fn (object $row): string => (string) $row->detail)
            ->implode(' ');

        $this->assertStringContainsString(
            'seasonvar_prepared_run_source_unique',
            $details,
        );
    }

    private function preparedPage(): SeasonvarImportPreparedPage
    {
        $run = $this->importRun();
        $group = $this->group($run, 'prepared');
        $sourcePage = SourcePage::factory()->create([
            'page_type' => 'serial',
        ]);

        return SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $sourcePage->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);
    }

    private function importRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'summary' => [
                'discovery_completed' => true,
                'dispatch_completed' => false,
            ],
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
    }

    private function group(
        SeasonvarImportRun $run,
        string $suffix,
    ): SeasonvarImportTitleGroup {
        return SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'query-plan-'.$suffix),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function forcedPlannerIds(int $runId): array
    {
        $ids = [];

        foreach (app(SeasonvarRefreshPlanner::class)->forcedPageChunks(
            100,
            $runId,
            pageTypes: ['serial'],
        ) as $pages) {
            array_push(
                $ids,
                ...$pages->pluck('id')->map(
                    static fn (mixed $id): int => (int) $id,
                )->all(),
            );
        }

        return $ids;
    }

    /** @return list<int> */
    private function automaticPlannerIds(int $runId): array
    {
        $ids = [];

        foreach (app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            100,
            now()->subDay(),
            $runId,
            pageTypes: ['serial'],
        ) as $pages) {
            array_push(
                $ids,
                ...$pages->pluck('id')->map(
                    static fn (mixed $id): int => (int) $id,
                )->all(),
            );
        }

        return $ids;
    }
}
