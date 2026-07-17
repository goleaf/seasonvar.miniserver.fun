<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheRebuildTimeout;
use App\Support\Cache\CacheTelemetry;
use Illuminate\Support\Facades\App;

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
     * @return array{
     *     started_at: string,
     *     finished_at: string,
     *     duration_ms: int,
     *     targets: array<string, int>,
     *     failed: int,
     *     failures: list<array{target: string, exception: class-string}>,
     *     public_pages: array{
     *         attempted: int,
     *         succeeded: int,
     *         failed: int,
     *         skipped: int,
     *         limited: bool,
     *         errors: list<array{fingerprint: string, status: int|null, exception: string|null}>
     *     }
     * }
     */
    public function warmCritical(bool $refresh = false, iterable $titleIds = []): array
    {
        $startedAt = now();
        $started = hrtime(true);
        $targets = [];
        $failures = [];
        $publicPages = [
            'attempted' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'limited' => false,
            'errors' => [],
        ];
        $originalLocale = App::currentLocale();
        $this->state->started();

        try {
            $targets['catalog_stats'] = $this->measureTarget(
                'catalog_stats',
                fn () => $refresh ? $this->stats->refresh() : $this->stats->snapshot(),
                $failures,
            );
            $targets['home_metrics'] = $this->measureLocales(
                'home_metrics',
                fn () => $refresh ? $this->homeMetrics->refresh() : $this->homeMetrics->metrics(),
                $failures,
            );
            $targets['home_snapshot'] = $this->measureLocales(
                'home_snapshot',
                fn () => $refresh ? $this->homeSnapshot->refresh() : $this->homeSnapshot->snapshot(),
                $failures,
            );
            $targets['home_genres'] = $this->measureLocales(
                'home_genres',
                fn () => $this->facets->taxonomies('genre', refresh: $refresh),
                $failures,
            );
            $targets['home_countries'] = $this->measureLocales(
                'home_countries',
                fn () => $this->facets->taxonomies('country', refresh: $refresh),
                $failures,
            );

            if ((bool) config('cache-architecture.page_cache.warming_enabled', true)) {
                $targets['public_pages'] = $this->measure(function () use (&$publicPages, $titleIds): void {
                    $publicPages = $this->pages->warm($titleIds);
                });
                $this->telemetry->increment(
                    CacheDomain::Operational,
                    'warming-page-failure',
                    $publicPages['failed'],
                );
                $this->telemetry->increment(
                    CacheDomain::Operational,
                    'warming-page-skipped',
                    $publicPages['skipped'],
                );
            }

            $result = [
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'targets' => $targets,
                'failed' => count($failures) + $publicPages['failed'] + $publicPages['skipped'],
                'failures' => $failures,
                'public_pages' => $publicPages,
            ];
            $this->state->succeeded($result);
            $this->telemetry->duration(CacheDomain::Operational, 'warming', $result['duration_ms']);

            return $result;
        } catch (\Throwable $exception) {
            $this->state->failed($exception);
            $this->telemetry->increment(CacheDomain::Operational, 'warming-failure');
            throw $exception;
        } finally {
            App::setLocale($originalLocale);
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

    /** @param list<array{target: string, exception: class-string}> $failures */
    private function measureTarget(string $target, callable $callback, array &$failures): int
    {
        $started = hrtime(true);

        try {
            $callback();
        } catch (CacheRebuildTimeout $exception) {
            $failures[] = [
                'target' => $target,
                'exception' => $exception::class,
            ];
            $this->telemetry->increment(CacheDomain::Operational, 'warming-lock-skip');
        }

        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    /** @param list<array{target: string, exception: class-string}> $failures */
    private function measureLocales(string $target, callable $callback, array &$failures): int
    {
        $started = hrtime(true);

        foreach ((array) config('catalog-collections.supported_locales', []) as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            App::setLocale($locale);

            try {
                $callback();
            } catch (CacheRebuildTimeout $exception) {
                $failures[] = [
                    'target' => $target.':'.$locale,
                    'exception' => $exception::class,
                ];
                $this->telemetry->increment(CacheDomain::Operational, 'warming-lock-skip');
            }
        }

        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
