<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class QueueWorkerHeartbeat
{
    public function __construct(
        private readonly CacheKeyFactory $keys,
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
    ) {}

    public function processing(JobProcessing $event): void
    {
        try {
            $payload = $event->job->payload();
            $createdAt = $payload['createdAt'] ?? null;
            $this->telemetry->increment(CacheDomain::Operational, 'queue-processed');

            if (is_int($createdAt) && $createdAt > 0 && $createdAt <= now()->getTimestamp()) {
                $this->telemetry->duration(
                    CacheDomain::Operational,
                    'queue-wait',
                    (now()->getTimestamp() - $createdAt) * 1_000,
                );
            }

            Cache::store((string) config('cache-architecture.stores.domain', 'redis-domain'))->put(
                $this->key(),
                [
                    'connection' => $event->connectionName,
                    'queue' => $event->job->getQueue(),
                    'processed_at' => now()->toIso8601String(),
                ],
                max(30, (int) config('cache-architecture.operations.queue_worker_heartbeat_seconds', 120)),
            );
        } catch (Throwable $exception) {
            report($exception);

            // Heartbeats are observational and must not fail a job.
        }
    }

    public function failed(JobFailed $event): void
    {
        $this->telemetry->increment(CacheDomain::Operational, 'queue-failure');
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        try {
            $heartbeat = Cache::memo((string) config('cache-architecture.stores.domain', 'redis-domain'))->get($this->key());
            $pending = Queue::connection('redis')->size((string) config('queue.connections.redis.queue', 'default'));

            if (! is_array($heartbeat)) {
                return [
                    'status' => 'unknown',
                    'pending' => $pending,
                    'message' => 'Heartbeat queue worker ещё не получен.',
                ];
            }

            return [
                'status' => 'ok',
                'pending' => $pending,
                'last_processed_at' => $heartbeat['processed_at'] ?? null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'failed', 'message' => 'Состояние queue worker недоступно.'];
        }
    }

    private function key(): string
    {
        return $this->keys->data(
            CacheDomain::Operational,
            'queue-worker-heartbeat',
            [],
            $this->versions->version(CacheDomain::Operational),
        );
    }
}
