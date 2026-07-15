<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class QueueWorkerHeartbeat
{
    /** @var array<string, int> */
    private array $lastHeartbeatAt = [];

    /** @var array<string, string> */
    private array $lastProcessedAt = [];

    public function __construct(
        private readonly CacheKeyFactory $keys,
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
        private readonly QueueManager $queues,
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

            $queue = (string) ($event->job->getQueue() ?: config('queue.connections.redis.queue', 'default'));
            $this->record($event->connectionName, $queue, processed: true);
        } catch (Throwable $exception) {
            report($exception);

            // Heartbeats are observational and must not fail a job.
        }
    }

    public function looping(Looping $event): void
    {
        try {
            $queues = array_values(array_unique(array_filter(
                array_map(trim(...), explode(',', (string) $event->queue)),
            )));

            foreach ($queues as $queue) {
                $this->record((string) $event->connectionName, $queue);
            }
        } catch (Throwable $exception) {
            report($exception);

            // Heartbeats are observational and must not stop a worker loop.
        }
    }

    public function failed(JobFailed $event): void
    {
        $this->telemetry->increment(CacheDomain::Operational, 'queue-failure');
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $statuses = [];

        foreach ($this->criticalQueues() as $name => $definition) {
            $statuses[$name] = $this->queueStatus(
                $definition['connection'],
                $definition['queue'],
                $definition['busy_threshold'],
            );
        }

        $states = array_column($statuses, 'status');
        $status = match (true) {
            in_array('failed', $states, true) => 'failed',
            in_array('degraded', $states, true) => 'degraded',
            in_array('ok', $states, true) => 'ok',
            default => 'unknown',
        };

        return [
            'status' => $status,
            'pending' => array_sum(array_column($statuses, 'pending')),
            'delayed' => array_sum(array_column($statuses, 'delayed')),
            'reserved' => array_sum(array_column($statuses, 'reserved')),
            'queues' => $statuses,
        ];
    }

    /**
     * @return array<string, array{connection: string, queue: string, busy_threshold: int}>
     */
    private function criticalQueues(): array
    {
        return [
            'default' => [
                'connection' => (string) config('queue.default', 'redis'),
                'queue' => (string) config('queue.connections.redis.queue', 'default'),
                'busy_threshold' => max(1, (int) config('cache-architecture.operations.default_queue_busy_threshold', 100)),
            ],
            'cache_warm' => [
                'connection' => (string) config('cache-architecture.warming.connection', 'redis'),
                'queue' => (string) config('cache-architecture.warming.queue', 'cache-warm-v2'),
                'busy_threshold' => max(1, (int) config('cache-architecture.operations.cache_warm_queue_busy_threshold', 1_000)),
            ],
            'seasonvar_import' => [
                'connection' => (string) config('seasonvar.queue.connection', 'redis'),
                'queue' => (string) config('seasonvar.queue.queue', 'seasonvar-import'),
                'busy_threshold' => max(1, (int) config('seasonvar.queue.busy_threshold', 5_000)),
            ],
            'seasonvar_title_refresh' => [
                'connection' => (string) config('seasonvar.queue.connection', 'redis'),
                'queue' => (string) config('seasonvar.title_refresh.queue', 'seasonvar-title-refresh'),
                'busy_threshold' => max(1, (int) config('seasonvar.queue.busy_threshold', 5_000)),
            ],
        ];
    }

    /** @return array{status: string, connection: string, queue: string, pending: int, delayed: int, reserved: int, oldest_pending_age_seconds: int|null, last_heartbeat_at: mixed, last_processed_at: mixed, message?: string} */
    private function queueStatus(string $connection, string $queueName, int $busyThreshold): array
    {
        try {
            $queue = $this->queues->connection($connection);
            $pending = (int) $queue->pendingSize($queueName);
            $delayed = (int) $queue->delayedSize($queueName);
            $reserved = (int) $queue->reservedSize($queueName);
            $oldestTimestamp = $queue->creationTimeOfOldestPendingJob($queueName);
            $heartbeat = Cache::store((string) config('cache-architecture.stores.domain', 'redis-domain'))
                ->get($this->key($connection, $queueName));
            $hasWork = $pending + $delayed + $reserved > 0;
            $status = ! is_array($heartbeat)
                ? ($hasWork ? 'failed' : 'idle')
                : ($pending + $delayed > $busyThreshold ? 'degraded' : 'ok');
            $message = ! is_array($heartbeat) && $hasWork
                ? 'Для очереди с ожидающей работой не получен heartbeat worker.'
                : null;

            $result = [
                'status' => $status,
                'connection' => $connection,
                'queue' => $queueName,
                'pending' => $pending,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'oldest_pending_age_seconds' => is_int($oldestTimestamp)
                    ? max(0, now()->getTimestamp() - $oldestTimestamp)
                    : null,
                'last_heartbeat_at' => is_array($heartbeat) ? ($heartbeat['heartbeat_at'] ?? null) : null,
                'last_processed_at' => is_array($heartbeat) ? ($heartbeat['processed_at'] ?? null) : null,
            ];

            if ($message !== null) {
                $result['message'] = $message;
            }

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'connection' => $connection,
                'queue' => $queueName,
                'pending' => 0,
                'delayed' => 0,
                'reserved' => 0,
                'oldest_pending_age_seconds' => null,
                'last_heartbeat_at' => null,
                'last_processed_at' => null,
                'message' => 'Состояние очереди или worker недоступно.',
            ];
        }
    }

    private function record(string $connection, string $queue, bool $processed = false): void
    {
        $now = now();
        $identity = $connection.':'.$queue;
        $ttl = max(30, (int) config('cache-architecture.operations.queue_worker_heartbeat_seconds', 120));
        $writeInterval = max(5, min(30, intdiv($ttl, 3)));

        if (! $processed && ($this->lastHeartbeatAt[$identity] ?? 0) > $now->getTimestamp() - $writeInterval) {
            return;
        }

        if ($processed) {
            $this->lastProcessedAt[$identity] = $now->toIso8601String();
        }

        Cache::store((string) config('cache-architecture.stores.domain', 'redis-domain'))->put(
            $this->key($connection, $queue),
            [
                'connection' => $connection,
                'queue' => $queue,
                'heartbeat_at' => $now->toIso8601String(),
                'processed_at' => $this->lastProcessedAt[$identity] ?? null,
            ],
            $ttl,
        );
        $this->lastHeartbeatAt[$identity] = $now->getTimestamp();
    }

    private function key(string $connection, string $queue): string
    {
        return $this->keys->data(
            CacheDomain::Operational,
            'queue-worker-heartbeat',
            ['connection' => $connection, 'queue' => $queue],
            $this->versions->version(CacheDomain::Operational),
        );
    }
}
