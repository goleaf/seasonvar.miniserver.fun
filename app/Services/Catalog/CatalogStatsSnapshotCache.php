<?php

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;
use Throwable;

class CatalogStatsSnapshotCache
{
    private const FRESH_MESSAGE = 'Снимок обновляется после изменений каталога и в плановом прогреве.';

    public function __construct(
        private readonly CatalogStatsSnapshotBuilder $builder,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
        private readonly CacheVersionRegistry $versions,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return $this->read(refresh: false);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function refresh(): array
    {
        return $this->read(refresh: true);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(bool $refresh): array
    {
        try {
            $arguments = [
                CacheDomain::CatalogStats,
                'dashboard',
                ['audience' => 'public', 'locale' => app()->getLocale()],
                $this->ttl->for(CacheDomain::CatalogStats),
                fn (): array => $this->buildSnapshot($this->builder->build()),
            ];
            $result = $refresh
                ? $this->cache->refresh(...$arguments)
                : $this->cache->remember(...$arguments);
            $snapshot = is_array($result->value) ? $result->value : $this->buildSnapshot($this->fallbackData());
            $snapshot['meta']['cache_source'] = $result->source;
            $snapshot['meta']['is_stale'] = $result->stale;
            $snapshot['meta']['message'] = $result->stale
                ? 'Не удалось собрать свежую статистику, показаны последние сохраненные данные.'
                : self::FRESH_MESSAGE;

            return $this->withServedAt($snapshot);
        } catch (Throwable $exception) {
            report($exception);

            $snapshot = $this->buildSnapshot($this->fallbackData());
            $snapshot['meta']['cache_source'] = 'fallback';
            $snapshot['meta']['is_stale'] = true;
            $snapshot['meta']['message'] = 'Статистика временно недоступна, показан безопасный пустой снимок.';

            return $this->withServedAt($snapshot);
        }
    }

    public function forget(): void
    {
        $this->versions->bump(CacheDomain::CatalogStats);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function buildSnapshot(array $data): array
    {
        $builtAt = now();

        return [
            'data' => $data,
            'meta' => [
                'built_at' => $builtAt->toIso8601String(),
                'built_at_display' => $builtAt->format('d.m.Y H:i:s'),
                'served_at_display' => $builtAt->format('d.m.Y H:i:s'),
                'is_stale' => false,
                'message' => self::FRESH_MESSAGE,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function withServedAt(array $snapshot): array
    {
        $snapshot['meta']['served_at_display'] = now()->format('d.m.Y H:i:s');
        $snapshot['meta']['is_stale'] = (bool) ($snapshot['meta']['is_stale'] ?? false);
        $snapshot['meta']['message'] = (string) ($snapshot['meta']['message'] ?? self::FRESH_MESSAGE);

        return [
            'data' => is_array($snapshot['data'] ?? null) ? $snapshot['data'] : $this->fallbackData(),
            'meta' => is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackData(): array
    {
        return [
            'statsHealthCards' => [],
            'statsPosterRows' => [],
            'statsIssueRows' => [],
            'qualityProgressRows' => [],
            'headlineStats' => [],
            'summarySections' => [],
            'qualitySections' => [],
            'timeWindowRows' => [],
            'recentImportRuns' => [],
            'pageStatsSections' => [],
            'routeRows' => [],
            'internalLinkRows' => [],
            'externalUrlFieldRows' => [],
            'databaseOptimizationSections' => [],
            'databaseExpectedIndexRows' => [],
            'databaseIndexRows' => [],
            'databaseOptimizationIssueRows' => [],
            'groupSections' => [],
            'taxonomyRows' => [],
            'databaseTables' => [],
            'seo' => $this->builder->seo(),
        ];
    }
}
