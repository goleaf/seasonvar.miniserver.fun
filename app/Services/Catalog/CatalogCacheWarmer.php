<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;

final class CatalogCacheWarmer
{
    public function __construct(
        private readonly CatalogStatsSnapshotCache $stats,
        private readonly CatalogHomeMetricsCache $homeMetrics,
        private readonly CatalogHomeSnapshotCache $homeSnapshot,
        private readonly CatalogFacetQuery $facets,
        private readonly CacheWarmingState $state,
        private readonly CacheTelemetry $telemetry,
        private readonly PublicPageCacheWarmer $pages,
    ) {}

    /**
     * @param  iterable<array-key, int|string>  $titleIds
     * @return array{started_at: string, finished_at: string, duration_ms: int, targets: array<string, int>}
     */
    public function warmCritical(bool $refresh = false, iterable $titleIds = []): array
    {
        $startedAt = now();
        $started = hrtime(true);
        $targets = [];
        $this->state->started();

        try {
            $targets['catalog_stats'] = $this->measure(fn () => $refresh ? $this->stats->refresh() : $this->stats->snapshot());
            $targets['home_metrics'] = $this->measure(fn () => $refresh ? $this->homeMetrics->refresh() : $this->homeMetrics->metrics());
            $targets['home_snapshot'] = $this->measure(fn () => $refresh ? $this->homeSnapshot->refresh() : $this->homeSnapshot->snapshot());
            $targets['home_genres'] = $this->measure(fn () => $this->facets->taxonomies('genre', refresh: $refresh));
            $targets['home_countries'] = $this->measure(fn () => $this->facets->taxonomies('country', refresh: $refresh));

            if ((bool) config('cache-architecture.page_cache.warming_enabled', true)) {
                $targets['public_pages'] = $this->measure(fn () => $this->pages->warm($titleIds));
            }
            $result = [
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'targets' => $targets,
            ];
            $this->state->succeeded($result);
            $this->telemetry->duration(CacheDomain::Operational, 'warming', $result['duration_ms']);

            return $result;
        } catch (\Throwable $exception) {
            $this->state->failed($exception);
            $this->telemetry->increment(CacheDomain::Operational, 'warming-failure');
            throw $exception;
        }
    }

    public function titleBatchLimit(): int
    {
        $configured = max(1, (int) config('cache-architecture.warming.request_batch_title_limit', 250));

        return (bool) config('cache-architecture.page_cache.warming_enabled', true)
            ? min($configured, $this->pages->titleCapacity())
            : $configured;
    }

    private function measure(callable $callback): int
    {
        $started = hrtime(true);
        $callback();

        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
