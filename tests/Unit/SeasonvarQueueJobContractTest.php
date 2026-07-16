<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\RefreshSeasonvarCatalogTitle;
use App\Jobs\RunSeasonvarImport;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Jobs\WakeSeasonvarImportFinalizers;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionObject;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarQueueJobContractTest extends TestCase
{
    public function test_every_importer_job_has_an_explicit_bounded_retry_and_failure_contract(): void
    {
        $this->travelTo('2026-07-16 12:00:00');
        config([
            'queue.connections.redis.retry_after' => 1200,
            'seasonvar.queue.worker_timeout' => 900,
            'seasonvar.queue.retry_window_seconds' => 21_600,
            'seasonvar.queue.claim_seconds' => 86_400,
            'seasonvar.title_refresh.active_seconds' => 21_900,
        ]);

        $attemptBoundJobs = [
            'sync import' => [new RunSeasonvarImport, 900, [60, 300, 900], 'seasonvar-import', 3600],
            'queued coordinator' => [new StartSeasonvarQueuedImport(2), 900, [60, 300, 900], 'seasonvar-coordinator:2', 21_600],
            'finalization watchdog' => [new WakeSeasonvarImportFinalizers, 120, [30, 120, 300], 'seasonvar-import-finalization-watchdog', 900],
        ];

        foreach ($attemptBoundJobs as $name => [$job, $timeout, $backoff, $uniqueId, $uniqueFor]) {
            $this->assertSame(3, $job->tries, "{$name} tries");
            $this->assertSame($timeout, $job->timeout, "{$name} timeout");
            $this->assertLessThan(1200, $job->timeout, "{$name} timeout must stay below retry_after");
            $this->assertSame($backoff, $job->backoff(), "{$name} backoff");
            $jobReflection = new ReflectionObject($job);

            $this->assertFalse($jobReflection->hasMethod('retryUntil'), "{$name} must remain attempt-bounded");
            $this->assertTrue($jobReflection->hasMethod('failed'), "{$name} failed callback");
            $this->assertInstanceOf(ShouldBeUnique::class, $job, "{$name} uniqueness");
            $this->assertSame($uniqueId, $job->uniqueId(), "{$name} unique ID");
            $this->assertSame($uniqueFor, $job->uniqueFor, "{$name} unique lifetime");
        }

        $deadlineBoundJobs = [
            'source page' => [new ImportSeasonvarSourcePage(3, 4, 'claim-token', 'group-key'), [60, 300, 900], 86_400],
            'prepared page' => [new PrepareSeasonvarImportTitlePage(5), [60, 300, 900], 86_400],
            'title group finalizer' => [new FinalizeSeasonvarImportTitleGroup(6), [30, 60, 300, 900], 86_400],
            'global finalizer' => [new FinalizeSeasonvarQueuedImport(7), [60, 300, 900], 172_800],
            'title refresh' => [new RefreshSeasonvarCatalogTitle(8), [60, 300, 900], 21_600],
        ];

        foreach ($deadlineBoundJobs as $name => [$job, $backoff, $deadlineSeconds]) {
            $this->assertSame(0, $job->tries, "{$name} tries");
            $this->assertSame(900, $job->timeout, "{$name} timeout");
            $this->assertLessThan(1200, $job->timeout, "{$name} timeout must stay below retry_after");
            $this->assertSame($backoff, $job->backoff(), "{$name} backoff");
            $this->assertSame(now()->addSeconds($deadlineSeconds)->getTimestamp(), $job->retryUntil()->getTimestamp(), "{$name} deadline");
            $this->assertTrue((new ReflectionObject($job))->hasMethod('failed'), "{$name} failed callback");
        }

        $sourcePageJob = $deadlineBoundJobs['source page'][0];
        $this->assertFalse((new ReflectionObject($sourcePageJob))->implementsInterface(ShouldBeUnique::class));
        $this->assertSame('seasonvar-prepared-page:5', $deadlineBoundJobs['prepared page'][0]->uniqueId());
        $this->assertSame(86_400, $deadlineBoundJobs['prepared page'][0]->uniqueFor);
        $this->assertSame('seasonvar-title-group-finalizer:6', $deadlineBoundJobs['title group finalizer'][0]->uniqueId());
        $this->assertSame(86_700, $deadlineBoundJobs['title group finalizer'][0]->uniqueFor);
        $this->assertSame('seasonvar-finalizer:7', $deadlineBoundJobs['global finalizer'][0]->uniqueId());
        $this->assertSame('catalog-title-refresh:8', $deadlineBoundJobs['title refresh'][0]->uniqueId());
        $this->assertSame(21_900, $deadlineBoundJobs['title refresh'][0]->uniqueFor);
    }

    public function test_watchdog_failure_log_is_low_cardinality_and_secret_free(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(
                'Watchdog финализации импорта Seasonvar завершился ошибкой.',
                Mockery::on(fn (array $context): bool => $context === [
                    'job' => 'seasonvar-import-finalization-watchdog',
                    'exception' => RuntimeException::class,
                ]),
            );

        (new WakeSeasonvarImportFinalizers)->failed(new RuntimeException('private queue payload and token'));
    }
}
