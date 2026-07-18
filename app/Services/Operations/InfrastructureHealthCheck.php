<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\PublicCatalogWarmStateStore;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Horizon;
use RuntimeException;
use Throwable;

final class InfrastructureHealthCheck
{
    public function __construct(
        private readonly CacheWarmingState $warming,
        private readonly PublicCatalogWarmStateStore $fullWarming,
        private readonly QueueWorkerHeartbeat $workers,
        private readonly CacheKeyFactory $keys,
    ) {}

    /** @return array{status: string, ready: bool, checked_at: string, components: array<string, array<string, mixed>>} */
    public function run(): array
    {
        $components = [
            'database' => $this->check(fn (): bool => DB::selectOne('select 1 as healthy') !== null),
            'redis_cache' => $this->redis('cache'),
            'redis_sessions' => $this->redis('sessions'),
            'redis_queues' => $this->redis('queues'),
            'redis_locks' => $this->redis('locks'),
            'memcached' => $this->memcached(),
            'queue_workers' => $this->workers->status(),
            'horizon' => class_exists(Horizon::class)
                ? ['status' => 'unknown', 'message' => 'Проверка Horizon выполняется отдельным process monitor.']
                : ['status' => 'not_configured'],
            'cache_warming' => $this->warmingState(),
            'full_cache_warming' => $this->fullWarmingState(),
        ];
        $critical = ['database', 'redis_sessions', 'redis_queues', 'redis_locks'];
        $ready = collect($critical)->every(fn (string $component): bool => $components[$component]['status'] === 'ok');
        $degraded = collect(['redis_cache', 'memcached', 'queue_workers', 'cache_warming', 'full_cache_warming'])
            ->contains(fn (string $component): bool => in_array(
                $components[$component]['status'],
                $component === 'queue_workers'
                    ? ['unknown', 'degraded', 'failed']
                    : ['degraded', 'failed'],
                true,
            ));

        return [
            'status' => ! $ready ? 'failed' : ($degraded ? 'degraded' : 'ok'),
            'ready' => $ready,
            'checked_at' => now()->toIso8601String(),
            'components' => $components,
        ];
    }

    /** @return array{status: string, ready: bool, checked_at: string} */
    public function readiness(): array
    {
        $components = [
            $this->check(fn (): bool => DB::selectOne('select 1 as healthy') !== null),
            $this->redis('sessions'),
            $this->redis('queues'),
            $this->redis('locks'),
        ];
        $ready = collect($components)
            ->every(fn (array $component): bool => $component['status'] === 'ok');

        return [
            'status' => $ready ? 'ok' : 'unavailable',
            'ready' => $ready,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{status: string, latency_ms?: int, message?: string} */
    private function redis(string $connection): array
    {
        $started = hrtime(true);

        try {
            $client = Redis::connection($connection);
            $reply = $client->command('ping');
            $healthy = $reply === true || strtoupper((string) $reply) === 'PONG';
            $metrics = $connection === 'cache' ? [
                'used_memory_bytes' => null,
                'maxmemory_bytes' => null,
                'evicted_keys' => null,
            ] : [];

            if ($connection === 'cache' && $healthy) {
                try {
                    $memory = $client->command('info', ['memory']);
                    $statistics = $client->command('info', ['stats']);
                    $metrics = [
                        'used_memory_bytes' => max(0, (int) ($memory['used_memory'] ?? 0)),
                        'maxmemory_bytes' => max(0, (int) ($memory['maxmemory'] ?? 0)),
                        'evicted_keys' => max(0, (int) ($statistics['evicted_keys'] ?? 0)),
                    ];
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return [
                'status' => $healthy ? 'ok' : 'failed',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ...$metrics,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => 'Соединение недоступно.',
            ];
        }
    }

    /** @return array{status: string, latency_ms?: int, message?: string} */
    private function memcached(): array
    {
        $key = $this->keys->data(
            CacheDomain::Operational,
            'health-probe',
            ['nonce' => bin2hex(random_bytes(12))],
            1,
        );

        $metrics = array_fill_keys([
            'get_hits',
            'get_misses',
            'evictions',
            'curr_items',
            'total_items',
            'bytes',
            'limit_maxbytes',
            'connections',
            'rejected_connections',
        ], 0);
        $health = $this->check(function () use ($key, &$metrics): bool {
            $store = Cache::store('memcached-hot');

            try {
                $store->put(
                    $key,
                    'ok',
                    max(1, (int) config('cache-architecture.operations.health_probe_seconds', 10)),
                );

                $healthy = $store->get($key) === 'ok';
                $memcached = $store->getStore();

                if (! $memcached instanceof MemcachedStore) {
                    throw new RuntimeException('Настроенный cache store не использует Memcached.');
                }

                $statistics = $memcached->getMemcached()->getStats();

                foreach ($statistics as $server) {
                    if (! is_array($server)) {
                        continue;
                    }

                    foreach (array_keys($metrics) as $metric) {
                        $source = $metric === 'connections' ? 'curr_connections' : $metric;
                        $metrics[$metric] += max(0, (int) ($server[$source] ?? 0));
                    }
                }

                return $healthy;
            } finally {
                try {
                    $store->forget($key);
                } catch (Throwable) {
                    // The failed component is already reflected by the check result.
                }
            }
        });

        return [...$health, ...$metrics];
    }

    /** @return array<string, mixed> */
    private function warmingState(): array
    {
        $state = $this->warming->read();

        if ($state === null) {
            return ['status' => 'unknown', 'message' => 'Критический прогрев ещё не зарегистрирован.'];
        }

        return [
            'status' => (string) ($state['status'] ?? 'unknown'),
            'finished_at' => $state['finished_at'] ?? null,
            'duration_ms' => $state['duration_ms'] ?? null,
            'failed' => max(0, (int) ($state['failed'] ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function fullWarmingState(): array
    {
        try {
            $state = $this->fullWarming->read();
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                ...$this->emptyFullWarmingMetrics(),
                'message' => 'Состояние полного прогрева недоступно.',
            ];
        }

        if ($state === null) {
            return ['status' => 'idle', ...$this->emptyFullWarmingMetrics()];
        }

        $sourceStatus = (string) ($state['status'] ?? 'unknown');
        $status = match ($sourceStatus) {
            'queued', 'running' => $this->fullWarmingIsStale($state) ? 'degraded' : 'running',
            'completed' => 'ok',
            'completed_with_failures' => 'degraded',
            'failed' => 'failed',
            default => 'degraded',
        };

        return [
            'status' => $status,
            'estimated' => max(0, (int) ($state['estimated'] ?? 0)),
            'attempted' => max(0, (int) ($state['attempted'] ?? 0)),
            'warmed' => max(0, (int) ($state['warmed'] ?? 0)),
            'failed' => max(0, (int) ($state['failed'] ?? 0)),
            'started_at' => $state['started_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
        ];
    }

    /** @return array{estimated: int, attempted: int, warmed: int, failed: int, started_at: null, updated_at: null, finished_at: null} */
    private function emptyFullWarmingMetrics(): array
    {
        return [
            'estimated' => 0,
            'attempted' => 0,
            'warmed' => 0,
            'failed' => 0,
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
        ];
    }

    /** @param array<string, mixed> $state */
    private function fullWarmingIsStale(array $state): bool
    {
        $updatedAt = $state['updated_at'] ?? null;

        if (! is_string($updatedAt)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($updatedAt)->isBefore(
                now()->subSeconds(max(60, (int) config('cache-architecture.warming.full_stale_seconds', 900))),
            );
        } catch (Throwable) {
            return true;
        }
    }

    /** @param callable(): bool $callback
     * @return array{status: string, latency_ms: int, message?: string}
     */
    private function check(callable $callback): array
    {
        $started = hrtime(true);

        try {
            $healthy = $callback();

            return [
                'status' => $healthy ? 'ok' : 'failed',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => 'Соединение недоступно.',
            ];
        }
    }
}
