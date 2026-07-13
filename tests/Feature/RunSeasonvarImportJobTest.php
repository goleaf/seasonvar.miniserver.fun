<?php

namespace Tests\Feature;

use App\Jobs\RunSeasonvarImport;
use App\Models\SeasonvarImportRun;
use App\Notifications\SeasonvarImportFailed;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunSeasonvarImportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::lock('seasonvar-import', 60)->forceRelease();

        parent::tearDown();
    }

    public function test_job_defines_retry_timeout_backoff_and_unique_lock_policy(): void
    {
        $job = new RunSeasonvarImport(argument: 'https://seasonvar.ru/serial-1-Test-1-season.html', force: true, discover: false);

        $this->assertSame(3, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('seasonvar-import', $job->uniqueId());
    }

    public function test_job_can_be_dispatched_with_scalar_import_options(): void
    {
        Queue::fake();

        RunSeasonvarImport::dispatch('https://seasonvar.ru/serial-1-Test-1-season.html', true, false);

        Queue::assertPushed(RunSeasonvarImport::class, function (RunSeasonvarImport $job): bool {
            return $job->argument === 'https://seasonvar.ru/serial-1-Test-1-season.html'
                && $job->force === true
                && $job->discover === false;
        });
    }

    public function test_job_runs_pipeline_once_without_forever_mode_when_lock_is_available(): void
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'status' => 'completed',
            'argument' => 'https://seasonvar.ru/serial-1-Test-1-season.html',
            'force' => true,
            'forever' => false,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline
            ->shouldReceive('run')
            ->once()
            ->withArgs(function (
                ?string $argument,
                bool $force,
                bool $forever,
                ?int $sleepSeconds,
                bool $discover,
                mixed $progress,
            ): bool {
                return $argument === 'https://seasonvar.ru/serial-1-Test-1-season.html'
                    && $force === true
                    && $forever === false
                    && $sleepSeconds === null
                    && $discover === false
                    && $progress === null;
            })
            ->andReturn($run);

        $job = new RunSeasonvarImport(argument: 'https://seasonvar.ru/serial-1-Test-1-season.html', force: true, discover: false);
        $job->handle($pipeline);

        $lock = Cache::lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_job_releases_itself_when_an_import_lock_is_already_held(): void
    {
        $lock = Cache::lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());

        try {
            $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
            $pipeline->shouldNotReceive('run');
            $job = (new RunSeasonvarImport)->withFakeQueueInteractions();

            $job->handle($pipeline);

            $job->assertReleased(delay: 300);
        } finally {
            $lock->release();
        }
    }

    public function test_job_logs_failure_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Очередной импорт Seasonvar завершился ошибкой.', Mockery::on(function (array $context): bool {
                return $context['argument'] === 'https://seasonvar.ru/serial-1-Test-1-season.html'
                    && $context['force'] === true
                    && $context['discover'] === false
                    && $context['exception'] === RuntimeException::class
                    && $context['error'] === 'network failed';
            }));

        (new RunSeasonvarImport(argument: 'https://seasonvar.ru/serial-1-Test-1-season.html', force: true, discover: false))
            ->failed(new RuntimeException('network failed'));
    }

    public function test_job_sends_on_demand_import_failure_notification_when_recipient_is_configured(): void
    {
        Notification::fake();
        Log::spy();
        config([
            'notifications.seasonvar_import_failed.mail_to' => 'ops@example.com',
            'notifications.seasonvar_import_failed.mail_to_name' => 'Ops',
        ]);

        (new RunSeasonvarImport(argument: 'https://seasonvar.ru/serial-1-Test-1-season.html', force: true, discover: false))
            ->failed(new RuntimeException('network failed'));

        Notification::assertSentOnDemand(
            SeasonvarImportFailed::class,
            function (SeasonvarImportFailed $notification, array $channels, object $notifiable): bool {
                return $channels === ['mail']
                    && $notifiable->routes['mail'] === ['ops@example.com' => 'Ops']
                    && $notification->argument === 'https://seasonvar.ru/serial-1-Test-1-season.html'
                    && $notification->force === true
                    && $notification->discover === false
                    && $notification->exceptionClass === RuntimeException::class;
            },
        );
    }
}
