<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\ReconcileSeasonvarQueuedImportRun;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarImportDispatchBatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeasonvarImportDispatchBatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
    }

    public function test_batch_claim_acquires_only_available_pages_with_one_exact_token(): void
    {
        $run = $this->importRun();
        $otherRun = $this->importRun();
        $pages = SourcePage::factory()->count(3)->create([
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $otherToken = $claims->claim($pages[2], $otherRun->id, 3600);

        $result = $claims->claimMany(
            [
                $pages[1]->id,
                $pages[0]->id,
                $pages[2]->id,
                $pages[1]->id,
                0,
            ],
            $run->id,
            3600,
        );

        $this->assertNotNull($otherToken);
        $this->assertNotSame('', $result['token']);
        $this->assertSame([
            $pages[0]->id,
            $pages[1]->id,
        ], $result['page_ids']);
        $this->assertSame(
            $result['token'],
            $pages[0]->fresh()->import_claim_token,
        );
        $this->assertSame(
            $result['token'],
            $pages[1]->fresh()->import_claim_token,
        );
        $this->assertSame(
            $otherToken,
            $pages[2]->fresh()->import_claim_token,
        );
    }

    public function test_dispatch_next_registers_serial_pages_in_one_bounded_batch(): void
    {
        $run = $this->importRun(force: true);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $pages = collect([
            'https://seasonvar.ru/serial-100-Pervyj_psalpha-1-season.html',
            'https://seasonvar.ru/serial-100-Pervyj_psalpha-2-season.html',
            'https://seasonvar.ru/serial-200-Vtoroj_psbeta-1-season.html',
        ])->map(fn (string $url): SourcePage => SourcePage::factory()
            ->for($source)
            ->create([
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => 'serial',
                'parse_status' => 'pending',
                'import_status' => 'pending',
            ]));

        $result = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);
        $freshRun = $run->fresh();

        $this->assertSame(3, $result->registeredPages);
        $this->assertSame(3, $result->jobsDispatched);
        $this->assertFalse($result->hasMore);
        $this->assertTrue($result->dispatchCompleted);
        $this->assertSame(3, $freshRun->selected);
        $this->assertSame(1, data_get(
            $freshRun->summary,
            'dispatch_batches',
        ));
        $this->assertTrue(data_get(
            $freshRun->summary,
            'dispatch_completed',
        ));
        $this->assertSame(2, SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->count());
        $this->assertSame(3, SeasonvarImportPreparedPage::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->count());
        $this->assertSame(
            [1, 2],
            SeasonvarImportTitleGroup::query()
                ->where('seasonvar_import_run_id', $run->id)
                ->orderBy('expected_pages')
                ->pluck('expected_pages')
                ->all(),
        );
        $this->assertSame(
            1,
            $pages->map(
                fn (SourcePage $page): ?string => $page->fresh()->import_claim_token,
            )->unique()->count(),
        );
        Queue::assertPushedTimes(PrepareSeasonvarImportTitlePage::class, 3);
        Queue::assertNotPushed(ReconcileSeasonvarQueuedImportRun::class);
    }

    private function importRun(bool $force = false): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => $force,
            'summary' => [
                'discover' => false,
                'discovery_completed' => true,
                'dispatch_completed' => false,
                'dispatch_batches' => 0,
                'page_types' => ['serial'],
            ],
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
