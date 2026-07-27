<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Services\Seasonvar\SeasonvarImportDispatchBatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeasonvarImporterLoadProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_dispatch_batch_stays_bounded_with_forty_eight_thousand_source_pages(): void
    {
        config([
            'seasonvar.import.chunk_size' => 100,
            'seasonvar.queue.lock_store' => 'array',
        ]);
        Queue::fake();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $timestamp = now()->toDateTimeString();

        foreach (array_chunk(range(1, 48_000), 500) as $indexes) {
            $rows = [];

            foreach ($indexes as $index) {
                $family = (($index - 1) % 10) + 1;
                $season = intdiv($index - 1, 10) + 1;
                $url = "https://seasonvar.ru/serial-{$family}-Load{$family}-{$season}-season.html";
                $rows[] = [
                    'source_id' => $source->id,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'page_type' => 'serial',
                    'parse_status' => 'pending',
                    'import_status' => 'pending',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::table('source_pages')->insert($rows);
        }

        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => true,
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
        $queries = 0;
        $slowestMilliseconds = 0.0;

        DB::listen(static function (QueryExecuted $query) use (&$queries, &$slowestMilliseconds): void {
            $queries++;
            $slowestMilliseconds = max($slowestMilliseconds, $query->time);
        });

        $startedAt = hrtime(true);
        $result = app(SeasonvarImportDispatchBatcher::class)->dispatchNext($run->id);
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertSame(100, $result->registeredPages);
        $this->assertTrue($result->hasMore);
        $this->assertFalse($result->dispatchCompleted);
        $this->assertSame(100, $run->fresh()->selected);
        $this->assertLessThanOrEqual(
            120,
            $queries,
            sprintf(
                '48k dispatch profile: %d queries, %.3f ms total, %.3f ms slowest query.',
                $queries,
                $elapsedMilliseconds,
                $slowestMilliseconds,
            ),
        );
    }
}
