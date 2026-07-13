<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class CacheMetricsSnapshot
{
    /** @var list<string> */
    private const METRICS = [
        'hot-hit',
        'hot-miss',
        'shared-hit',
        'shared-miss',
        'stale-hit',
        'stale-miss',
        'hot-write',
        'shared-write',
        'stale-write',
        'stale-served',
        'stale-served-on-error',
        'rebuild-count',
        'rebuild-milliseconds',
        'rebuild-failure',
        'lock-timeout',
        'payload-rejected',
        'failure',
        'invalidation',
        'warming-count',
        'warming-milliseconds',
        'warming-failure',
        'warming-dispatch-failure',
        'queue-processed',
        'queue-wait-count',
        'queue-wait-milliseconds',
        'queue-failure',
        'cache-payload-count',
        'cache-payload-bytes',
        'framework-hit',
        'framework-miss',
        'framework-write',
        'framework-forget',
        'framework-failover',
    ];

    public function __construct(private readonly CacheKeyFactory $keys) {}

    /** @return array{date: string, totals: array<string, int|float>, domains: array<string, array<string, int|float>>} */
    public function forDate(?string $date = null): array
    {
        $date ??= now()->format('Y-m-d');
        $domains = [];

        foreach (CacheDomain::cases() as $domain) {
            $metrics = $this->domain($domain, $date);

            if (array_sum(array_filter($metrics, 'is_int')) > 0) {
                $domains[$domain->value] = $metrics;
            }
        }

        $totals = $this->aggregate($domains);

        return ['date' => $date, 'totals' => $totals, 'domains' => $domains];
    }

    /** @return array<string, int|float> */
    private function domain(CacheDomain $domain, string $date): array
    {
        $metrics = [];

        foreach (self::METRICS as $metric) {
            $metrics[$metric] = $this->read($this->keys->metric($domain, $metric, $date));
        }

        $hits = $metrics['hot-hit'] + $metrics['shared-hit'] + $metrics['stale-hit'];
        $misses = $metrics['hot-miss'] + $metrics['shared-miss'] + $metrics['stale-miss'];
        $metrics['hit-ratio'] = $hits + $misses === 0 ? 0.0 : round($hits / ($hits + $misses), 4);
        $metrics['average-rebuild-ms'] = $metrics['rebuild-count'] === 0
            ? 0.0
            : round($metrics['rebuild-milliseconds'] / $metrics['rebuild-count'], 2);
        $metrics['average-warming-ms'] = $metrics['warming-count'] === 0
            ? 0.0
            : round($metrics['warming-milliseconds'] / $metrics['warming-count'], 2);
        $metrics['average-queue-wait-ms'] = $metrics['queue-wait-count'] === 0
            ? 0.0
            : round($metrics['queue-wait-milliseconds'] / $metrics['queue-wait-count'], 2);
        $metrics['average-cache-payload-bytes'] = $metrics['cache-payload-count'] === 0
            ? 0.0
            : round($metrics['cache-payload-bytes'] / $metrics['cache-payload-count'], 2);

        return $metrics;
    }

    /**
     * @param  array<string, array<string, int|float>>  $domains
     * @return array<string, int|float>
     */
    private function aggregate(array $domains): array
    {
        $totals = array_fill_keys(self::METRICS, 0);

        foreach ($domains as $metrics) {
            foreach (self::METRICS as $metric) {
                $totals[$metric] += (int) ($metrics[$metric] ?? 0);
            }
        }

        $hits = $totals['hot-hit'] + $totals['shared-hit'] + $totals['stale-hit'];
        $misses = $totals['hot-miss'] + $totals['shared-miss'] + $totals['stale-miss'];
        $totals['hit-ratio'] = $hits + $misses === 0 ? 0.0 : round($hits / ($hits + $misses), 4);
        $totals['average-rebuild-ms'] = $totals['rebuild-count'] === 0
            ? 0.0
            : round($totals['rebuild-milliseconds'] / $totals['rebuild-count'], 2);
        $totals['average-warming-ms'] = $totals['warming-count'] === 0
            ? 0.0
            : round($totals['warming-milliseconds'] / $totals['warming-count'], 2);
        $totals['average-queue-wait-ms'] = $totals['queue-wait-count'] === 0
            ? 0.0
            : round($totals['queue-wait-milliseconds'] / $totals['queue-wait-count'], 2);
        $totals['average-cache-payload-bytes'] = $totals['cache-payload-count'] === 0
            ? 0.0
            : round($totals['cache-payload-bytes'] / $totals['cache-payload-count'], 2);

        return $totals;
    }

    private function read(string $key): int
    {
        try {
            return max(0, (int) Cache::store((string) config('cache-architecture.stores.metrics', 'redis-domain'))->get($key, 0));
        } catch (Throwable) {
            return 0;
        }
    }
}
