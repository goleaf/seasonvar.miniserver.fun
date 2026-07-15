<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReviewCacheInvalidator
{
    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
    ) {}

    public function titleChanged(
        int $catalogTitleId,
        bool $recommendations = false,
        bool $api = false,
    ): void {
        $this->titlesChanged([$catalogTitleId], $recommendations, $api);
    }

    /** @param iterable<int, int|string> $catalogTitleIds */
    public function titlesChanged(
        iterable $catalogTitleIds,
        bool $recommendations = false,
        bool $api = false,
    ): void {
        $ids = collect($catalogTitleIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $maximumTargeted = max(1, (int) config('reviews.maximum_targeted_cache_titles', 1_000));
        $invalidate = count($ids) > $maximumTargeted
            ? fn () => $this->invalidateAll($recommendations, $api)
            : fn () => $this->invalidate($ids, $recommendations, $api);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    /** @param list<int> $catalogTitleIds */
    private function invalidate(array $catalogTitleIds, bool $recommendations, bool $api): void
    {
        try {
            foreach ($catalogTitleIds as $catalogTitleId) {
                $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$catalogTitleId);
                $this->telemetry->increment(CacheDomain::TitleDetail, 'review-invalidation');
            }

            if ($recommendations) {
                $this->versions->bump(CacheDomain::Recommendations);
                $this->telemetry->increment(CacheDomain::Recommendations, 'review-invalidation');
            }

            if ($api) {
                $this->versions->bump(CacheDomain::Api);
                $this->telemetry->increment(CacheDomain::Api, 'review-invalidation');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function invalidateAll(bool $recommendations, bool $api): void
    {
        try {
            $this->versions->bump(CacheDomain::TitleDetail);
            $this->telemetry->increment(CacheDomain::TitleDetail, 'review-global-invalidation');

            if ($recommendations) {
                $this->versions->bump(CacheDomain::Recommendations);
                $this->telemetry->increment(CacheDomain::Recommendations, 'review-invalidation');
            }

            if ($api) {
                $this->versions->bump(CacheDomain::Api);
                $this->telemetry->increment(CacheDomain::Api, 'review-invalidation');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
