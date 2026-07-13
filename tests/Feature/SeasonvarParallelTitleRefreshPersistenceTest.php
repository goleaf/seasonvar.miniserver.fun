<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonvarParallelTitleRefreshPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_and_prepared_page_are_run_scoped_and_typed(): void
    {
        $run = $this->queuedRun();
        $title = CatalogTitle::factory()->create();
        $page = SourcePage::factory()->create();
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'catalog_title_id' => $title->id,
            'group_key_hash' => hash('sha256', 'seasonvar-title:family'),
            'queue_name' => 'seasonvar-title-refresh',
            'status' => SeasonvarImportTitleGroupStatus::Discovering,
        ]);
        $prepared = $group->preparedPages()->create([
            'seasonvar_import_run_id' => $run->id,
            'source_page_id' => $page->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
            'warnings' => [],
        ]);

        $this->assertTrue($run->fresh()->titleGroups->first()?->is($group));
        $this->assertTrue($group->fresh()->catalogTitle?->is($title));
        $this->assertTrue($prepared->fresh()->sourcePage->is($page));
        $this->assertSame(SeasonvarImportTitleGroupStatus::Discovering, $group->fresh()->status);
        $this->assertSame(SeasonvarPreparedPageStatus::Queued, $prepared->fresh()->status);
        $this->assertSame([], $prepared->fresh()->warnings);
        $this->assertDatabaseCount('seasonvar_import_title_groups', 1);
        $this->assertDatabaseCount('seasonvar_import_prepared_pages', 1);
    }

    public function test_group_supports_a_new_global_title_and_prepared_pages_are_idempotent_per_group(): void
    {
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'catalog_title_id' => null,
            'group_key_hash' => hash('sha256', 'seasonvar-title:new-family'),
            'queue_name' => 'seasonvar-import',
            'status' => SeasonvarImportTitleGroupStatus::Discovering,
        ]);
        SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
        ]);

        $this->expectException(QueryException::class);

        SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => SeasonvarPreparedPageStatus::Queued,
        ]);
    }

    private function queuedRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => true,
            'forever' => false,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
