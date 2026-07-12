<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarQueueMonitor;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SeasonvarQueueMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.queue.busy_log_seconds' => 3600,
        ]);
        Cache::store('array')->flush();
        Log::spy();
    }

    public function test_it_logs_a_busy_seasonvar_queue_only_once_per_window(): void
    {
        $this->assertTrue(class_exists(SeasonvarQueueMonitor::class));

        $monitor = app(SeasonvarQueueMonitor::class);
        $event = new QueueBusy('redis', 'seasonvar-import', 5000);

        $monitor->busy($event);
        $monitor->busy($event);
        $monitor->busy(new QueueBusy('redis', 'default', 5000));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Очередь импорта Seasonvar перегружена.'
                && $context['connection'] === 'redis'
                && $context['queue'] === 'seasonvar-import'
                && $context['size'] === 5000);
    }

    public function test_it_logs_only_seasonvar_job_exceptions_with_context(): void
    {
        $this->assertTrue(class_exists(SeasonvarQueueMonitor::class));

        $seasonvarJob = $this->job('seasonvar-import');
        $defaultJob = $this->job('default');
        $exception = new RuntimeException('Temporary source failure.');
        $monitor = app(SeasonvarQueueMonitor::class);

        $monitor->exceptionOccurred(new JobExceptionOccurred('redis', $seasonvarJob, $exception));
        $monitor->exceptionOccurred(new JobExceptionOccurred('redis', $defaultJob, $exception));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Попытка queue job Seasonvar завершилась исключением.'
                && $context['job'] === 'App\\Jobs\\ImportSeasonvarSourcePage'
                && $context['uuid'] === 'job-uuid'
                && $context['attempt'] === 2
                && $context['exception'] === RuntimeException::class);
    }

    private function job(string $queue): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getConnectionName')->andReturn('redis');
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ImportSeasonvarSourcePage');
        $job->shouldReceive('uuid')->andReturn('job-uuid');
        $job->shouldReceive('attempts')->andReturn(2);

        return $job;
    }
}
