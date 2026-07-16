<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CatalogRecommendationCacheInvalidator
{
    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
    ) {}

    public function publicSignalsChanged(string $reason): void
    {
        $invalidate = function () use ($reason): void {
            try {
                $this->versions->bump(CacheDomain::Recommendations);
                $this->telemetry->increment(CacheDomain::Recommendations, $reason);
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }
}
