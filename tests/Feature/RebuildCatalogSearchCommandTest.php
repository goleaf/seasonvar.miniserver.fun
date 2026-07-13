<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RebuildCatalogSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_refuses_to_run_while_an_import_is_active(): void
    {
        SeasonvarImportRun::query()->create([
            'mode' => 'full',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->artisan('catalog:search-rebuild')
            ->expectsOutputToContain('активен импорт')
            ->assertFailed();

        $this->assertSame(CatalogSearchIndexStatus::Building, $this->state()->status);
        $this->assertSame(0, $this->state()->checkpoint_id);
    }

    public function test_rebuild_resumes_from_checkpoint_and_marks_matching_index_ready(): void
    {
        $titles = CatalogTitle::factory()->count(3)->create();
        $indexer = app(CatalogSearchIndexer::class);
        $indexer->indexTitleIds([$titles[0]->id]);
        $this->state()->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Building,
            'checkpoint_id' => $titles[0]->id,
            'source_count' => 3,
        ]);

        $this->artisan('catalog:search-rebuild', ['--chunk' => 1])
            ->expectsOutputToContain('возобновлена')
            ->assertSuccessful();

        $state = $this->state()->fresh();

        $this->assertSame(CatalogSearchIndexStatus::Ready, $state->status);
        $this->assertSame(3, $state->source_count);
        $this->assertSame(3, $state->document_count);
        $this->assertSame($titles->max('id'), $state->checkpoint_id);
        $this->assertNotNull($state->completed_at);
        $this->assertNull($state->last_error);
    }

    public function test_rebuild_records_failed_state_when_fts_integrity_fails(): void
    {
        CatalogTitle::factory()->create();
        $indexer = Mockery::mock(CatalogSearchIndexer::class);
        $indexer->shouldReceive('sourceCount')->once()->andReturn(1);
        $indexer->shouldReceive('pruneOutOfScopeDocuments')->once()->andReturn(0);
        $indexer->shouldReceive('rebuildFromCheckpoint')->once()->andReturnUsing(
            function (int $checkpoint, int $chunkSize, callable $progress): int {
                $progress(1, ['requested' => 1, 'indexed' => 1, 'unchanged' => 0, 'deleted' => 0]);

                return 1;
            },
        );
        $indexer->shouldReceive('documentCount')->times(3)->andReturn(1);
        $indexer->shouldReceive('integrityCheck')->once()->andReturnFalse();
        $this->app->instance(CatalogSearchIndexer::class, $indexer);

        $this->artisan('catalog:search-rebuild')
            ->expectsOutputToContain('проверку целостности')
            ->assertFailed();

        $state = $this->state()->fresh();

        $this->assertSame(CatalogSearchIndexStatus::Failed, $state->status);
        $this->assertNotNull($state->failed_at);
        $this->assertNotNull($state->last_error);
        $this->assertNull($state->completed_at);
    }

    public function test_rebuild_records_failed_state_when_document_count_does_not_match(): void
    {
        $indexer = Mockery::mock(CatalogSearchIndexer::class);
        $indexer->shouldReceive('sourceCount')->once()->andReturn(2);
        $indexer->shouldReceive('documentCount')->times(3)->andReturn(0, 1, 1);
        $indexer->shouldReceive('pruneOutOfScopeDocuments')->once()->andReturn(0);
        $indexer->shouldReceive('rebuildFromCheckpoint')->once()->andReturnUsing(
            function (int $checkpoint, int $chunkSize, callable $progress): int {
                $progress(1, ['requested' => 1, 'indexed' => 1, 'unchanged' => 0, 'deleted' => 0]);

                return 1;
            },
        );
        $indexer->shouldNotReceive('integrityCheck');
        $this->app->instance(CatalogSearchIndexer::class, $indexer);

        $this->artisan('catalog:search-rebuild')
            ->expectsOutputToContain('не совпало с каталогом')
            ->assertFailed();

        $state = $this->state()->fresh();

        $this->assertSame(CatalogSearchIndexStatus::Failed, $state->status);
        $this->assertSame(2, $state->source_count);
        $this->assertSame(1, $state->document_count);
        $this->assertSame(1, $state->checkpoint_id);
    }

    private function state(): CatalogSearchIndexState
    {
        return CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID);
    }
}
