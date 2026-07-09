<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Throwable;

class CatalogStatsSnapshotCache
{
    private const FRESH_KEY = 'catalog.stats.snapshot.fresh';

    private const STALE_KEY = 'catalog.stats.snapshot.last_successful';

    private const LOCK_KEY = 'catalog.stats.snapshot.rebuild';

    private const FRESH_SECONDS = 1;

    private const STALE_SECONDS = 900;

    public function __construct(
        private readonly CatalogStatsSnapshotBuilder $builder,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function snapshot(): array
    {
        $cached = Cache::get(self::FRESH_KEY);

        if (is_array($cached)) {
            return $this->withServedAt($cached);
        }

        $lock = Cache::lock(self::LOCK_KEY, 5);

        if ($lock->get()) {
            try {
                return $this->refresh();
            } finally {
                $lock->release();
            }
        }

        return $this->staleSnapshot('Снимок обновляется, показаны последние сохраненные данные.');
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function refresh(): array
    {
        try {
            $snapshot = $this->buildSnapshot($this->builder->build());

            Cache::put(self::FRESH_KEY, $snapshot, now()->addSeconds(self::FRESH_SECONDS));
            Cache::put(self::STALE_KEY, $snapshot, now()->addSeconds(self::STALE_SECONDS));

            return $this->withServedAt($snapshot);
        } catch (Throwable $exception) {
            report($exception);

            return $this->staleSnapshot('Не удалось собрать свежую статистику, показаны последние сохраненные данные.');
        }
    }

    public function forget(): void
    {
        Cache::forget(self::FRESH_KEY);
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
                'message' => 'Данные обновляются каждую секунду.',
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function staleSnapshot(string $message): array
    {
        $snapshot = Cache::get(self::STALE_KEY);

        if (! is_array($snapshot)) {
            $snapshot = $this->buildSnapshot($this->fallbackData());
        }

        $snapshot['meta']['is_stale'] = true;
        $snapshot['meta']['message'] = $message;

        return $this->withServedAt($snapshot);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function withServedAt(array $snapshot): array
    {
        $snapshot['meta']['served_at_display'] = now()->format('d.m.Y H:i:s');
        $snapshot['meta']['is_stale'] = (bool) ($snapshot['meta']['is_stale'] ?? false);
        $snapshot['meta']['message'] = (string) ($snapshot['meta']['message'] ?? 'Данные обновляются каждую секунду.');

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
