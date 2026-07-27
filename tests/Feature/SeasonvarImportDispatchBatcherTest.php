<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportSeasonvarSourcePage;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function test_dispatch_next_preserves_non_serial_id_token_job_contract(): void
    {
        config([
            'seasonvar.page_types.actor.enabled' => true,
            'seasonvar.page_types.actor.automatic' => false,
        ]);
        $run = $this->importRun(force: true, pageTypes: ['actor']);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()
            ->for($source)
            ->create([
                'url' => 'https://seasonvar.ru/actor-Ivan-Ivanov.html',
                'url_hash' => hash(
                    'sha256',
                    'https://seasonvar.ru/actor-Ivan-Ivanov.html',
                ),
                'page_type' => 'actor',
                'parse_status' => 'pending',
                'import_status' => 'pending',
            ]);

        $result = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);

        $this->assertSame(1, $result->registeredPages);
        $this->assertSame(1, $result->jobsDispatched);
        $this->assertTrue($result->dispatchCompleted);
        $this->assertSame(1, $run->fresh()->selected);
        $this->assertNotNull($page->fresh()->import_claim_token);
        Queue::assertPushed(
            ImportSeasonvarSourcePage::class,
            fn (ImportSeasonvarSourcePage $job): bool => $job->sourcePageId === $page->id
                && $job->importRunId === $run->id
                && $job->claimToken === $page->fresh()->import_claim_token
                && $job->force,
        );
        Queue::assertNotPushed(PrepareSeasonvarImportTitlePage::class);
    }

    public function test_serial_registration_is_idempotent_after_dispatch_exhaustion(): void
    {
        $run = $this->importRun(force: true);
        $this->serialPages(3);

        $first = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);
        $second = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);

        $this->assertSame(3, $first->registeredPages);
        $this->assertSame(0, $second->registeredPages);
        $this->assertSame(0, $second->jobsDispatched);
        $this->assertTrue($second->dispatchCompleted);
        $this->assertSame(3, $run->fresh()->selected);
        $this->assertSame(
            3,
            SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_run_id', $run->id)
                ->count(),
        );
    }

    public function test_dispatch_is_capped_at_one_hundred_and_resumes_the_remainder(): void
    {
        config(['seasonvar.import.chunk_size' => 1000]);
        $run = $this->importRun(force: true);
        $this->serialPages(101);

        $first = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);

        $this->assertSame(100, $first->registeredPages);
        $this->assertTrue($first->hasMore);
        $this->assertFalse($first->dispatchCompleted);
        $this->assertFalse(data_get(
            $run->fresh()->summary,
            'dispatch_completed',
        ));
        Queue::assertPushed(
            ReconcileSeasonvarQueuedImportRun::class,
            fn (ReconcileSeasonvarQueuedImportRun $job): bool => $job->importRunId === $run->id,
        );

        $second = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);

        $this->assertSame(1, $second->registeredPages);
        $this->assertFalse($second->hasMore);
        $this->assertTrue($second->dispatchCompleted);
        $this->assertSame(101, $run->fresh()->selected);
        $this->assertSame(
            101,
            SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_run_id', $run->id)
                ->count(),
        );
    }

    public function test_one_hundred_serial_pages_use_a_bounded_sql_shape(): void
    {
        $run = $this->importRun(force: true);
        $this->serialPages(100, families: 10);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(SeasonvarImportDispatchBatcher::class)
            ->dispatchNext($run->id);

        $this->assertSame(100, $result->registeredPages);
        $this->assertLessThanOrEqual(120, count($queries));
        $this->assertSame(100, $run->fresh()->selected);
        $this->assertSame(100, $run->preparedPages()->count());
        $this->assertSame(10, $run->titleGroups()->count());

        $repeatedShapes = collect($queries)
            ->map(static fn (string $sql): string => preg_replace(
                '/\s+/',
                ' ',
                trim($sql),
            ) ?? $sql)
            ->countBy()
            ->filter(static fn (int $count): bool => $count >= 100);

        $this->assertSame([], $repeatedShapes->all());
    }

    /**
     * @param  list<string>  $pageTypes
     */
    private function importRun(
        bool $force = false,
        array $pageTypes = ['serial'],
    ): SeasonvarImportRun {
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
                'page_types' => $pageTypes,
            ],
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, SourcePage>
     */
    private function serialPages(
        int $count,
        int $families = 1,
    ): Collection {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);

        return collect(range(1, $count))->map(
            function (int $index) use ($families, $source): SourcePage {
                $family = (($index - 1) % max(1, $families)) + 1;
                $season = intdiv($index - 1, max(1, $families)) + 1;
                $url = "https://seasonvar.ru/serial-{$family}-Title{$family}_pshash{$family}-{$season}-season.html";

                return SourcePage::factory()
                    ->for($source)
                    ->create([
                        'url' => $url,
                        'url_hash' => hash('sha256', $url),
                        'page_type' => 'serial',
                        'parse_status' => 'pending',
                        'import_status' => 'pending',
                    ]);
            },
        );
    }
}
