<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeasonvarActiveRunQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_run_recovery_query_uses_the_composite_ledger_index(): void
    {
        $preparedPage = $this->preparedPage();
        $query = SeasonvarImportPreparedPage::query()
            ->select([
                'id',
                'seasonvar_import_title_group_id',
                'status',
                'updated_at',
            ])
            ->where('seasonvar_import_run_id', $preparedPage->seasonvar_import_run_id)
            ->whereIn('status', [
                SeasonvarPreparedPageStatus::Queued->value,
                SeasonvarPreparedPageStatus::Preparing->value,
            ])
            ->where('updated_at', '<=', now()->subMinutes(20))
            ->orderBy('id')
            ->limit(251);

        $plan = DB::select(
            'EXPLAIN QUERY PLAN '.$query->toSql(),
            $query->getBindings(),
        );
        $details = collect($plan)
            ->map(static fn (object $row): string => (string) $row->detail)
            ->implode(' ');

        $this->assertStringContainsString(
            'seasonvar_prepared_run_status_updated_id_idx',
            $details,
        );

        $columns = collect(DB::select(
            "PRAGMA index_info('seasonvar_prepared_run_status_updated_id_idx')",
        ))->pluck('name')->all();

        $this->assertSame([
            'seasonvar_import_run_id',
            'status',
            'updated_at',
            'id',
        ], $columns);
    }

    public function test_recovery_index_rollback_preserves_rows_and_existing_indexes(): void
    {
        $preparedPage = $this->preparedPage();
        $migration = require database_path(
            'migrations/2026_07_25_120000_add_active_run_recovery_index_to_seasonvar_import_prepared_pages.php',
        );

        $migration->down();

        $indexNames = collect(Schema::getIndexes(
            'seasonvar_import_prepared_pages',
        ))->pluck('name');

        $this->assertDatabaseHas('seasonvar_import_prepared_pages', [
            'id' => $preparedPage->id,
        ]);
        $this->assertTrue($indexNames->contains(
            'seasonvar_import_prepared_pages_group_page_unique',
        ));
        $this->assertTrue($indexNames->contains(
            'seasonvar_import_prepared_pages_group_status_idx',
        ));
        $this->assertFalse($indexNames->contains(
            'seasonvar_prepared_run_status_updated_id_idx',
        ));

        $migration->up();
    }

    private function preparedPage(): SeasonvarImportPreparedPage
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 1,
            'summary' => [
                'dispatch_completed' => true,
            ],
            'started_at' => now()->subHour(),
        ]);
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'query-plan-'.$run->id),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 1,
            'started_at' => now()->subHour(),
        ]);
        $sourcePage = SourcePage::factory()->create([
            'page_type' => 'serial',
        ]);
        $preparedPage = SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $sourcePage->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);

        DB::table($preparedPage->getTable())
            ->where('id', $preparedPage->id)
            ->update([
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);

        return $preparedPage->fresh();
    }
}
