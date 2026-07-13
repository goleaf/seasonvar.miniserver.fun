<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class CacheTelemetry
{
    public function __construct(private readonly CacheKeyFactory $keys) {}

    public function increment(CacheDomain $domain, string $metric, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        try {
            $store = Cache::store((string) config('cache-architecture.stores.metrics', 'redis-domain'));
            $key = $this->keys->metric($domain, $metric, now()->format('Y-m-d'));
            $store->add($key, 0, (int) config('cache-architecture.metrics_retention_seconds', 604_800));
            $store->increment($key, $by);
        } catch (Throwable) {
            // Telemetry must never take the application read path down.
        }
    }

    public function duration(CacheDomain $domain, string $metric, int $milliseconds): void
    {
        $this->increment($domain, $metric.'-count');
        $this->increment($domain, $metric.'-milliseconds', max(1, $milliseconds));
    }
}
