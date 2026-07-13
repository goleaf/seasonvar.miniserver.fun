<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class CacheEventReporter
{
    public function record(CacheEvent $event, string $metric): void
    {
        if (! (bool) config('cache-architecture.framework_events.enabled', true)
            || str_contains($event->key, ':cache-metrics:')) {
            return;
        }

        $this->increment($metric);
    }

    public function failedOver(CacheFailedOver $event): void
    {
        $this->increment('failover');

        Log::error('Основной cache store переключился на резервный store.', [
            'store' => $event->storeName,
            'exception' => $event->exception::class,
        ]);
    }

    private function increment(string $metric): void
    {
        if (! (bool) config('cache-architecture.framework_events.enabled', true)) {
            return;
        }

        try {
            $connection = Redis::connection((string) config('cache-architecture.framework_events.connection', 'cache'));
            $key = app(CacheKeyFactory::class)->metric(CacheDomain::Operational, 'framework-'.$metric, now()->format('Y-m-d'));
            $store = Cache::store((string) config('cache-architecture.stores.metrics', 'redis-domain'))->getStore();

            if (method_exists($store, 'getPrefix')) {
                $key = $store->getPrefix().$key;
            }

            $connection->incr($key);
            $connection->expire($key, (int) config('cache-architecture.metrics_retention_seconds', 604_800));
        } catch (Throwable) {
            // Observability must not affect cache or request correctness.
        }
    }
}
