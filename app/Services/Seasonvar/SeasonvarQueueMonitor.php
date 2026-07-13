<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SeasonvarQueueMonitor
{
    public function __construct(private readonly SeasonvarImportErrorSanitizer $errors) {}

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        if (! $this->matches($event->connectionName, $event->job->getQueue())) {
            return;
        }

        Log::warning('Попытка queue job Seasonvar завершилась исключением.', [
            ...$this->jobContext($event->job),
            'exception' => $event->exception::class,
            'error' => $this->errors->fromException($event->exception),
        ]);
    }

    public function failed(JobFailed $event): void
    {
        if (! $this->matches($event->connectionName, $event->job->getQueue())) {
            return;
        }

        Log::error('Queue job Seasonvar окончательно завершилась ошибкой.', [
            ...$this->jobContext($event->job),
            'exception' => $event->exception::class,
            'error' => $this->errors->fromException($event->exception),
        ]);
    }

    public function busy(QueueBusy $event): void
    {
        if (! $this->matches($event->connectionName, $event->queue)) {
            return;
        }

        $seconds = max(60, (int) config('seasonvar.queue.busy_log_seconds', 3600));
        $logged = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'))
            ->add('seasonvar-queue-busy-log', true, $seconds);

        if (! $logged) {
            return;
        }

        Log::warning('Очередь импорта Seasonvar перегружена.', [
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'size' => $event->size,
        ]);
    }

    /**
     * @return array{connection: string|null, queue: string|null, job: string, uuid: string|null, attempt: int}
     */
    private function jobContext(Job $job): array
    {
        return [
            'connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'job' => $job->resolveName(),
            'uuid' => $job->uuid(),
            'attempt' => $job->attempts(),
        ];
    }

    private function matches(string $connection, string $queue): bool
    {
        return $connection === (string) config('seasonvar.queue.connection', 'redis')
            && $queue === (string) config('seasonvar.queue.queue', 'seasonvar-import');
    }
}
