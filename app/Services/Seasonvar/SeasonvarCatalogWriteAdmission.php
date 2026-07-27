<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SeasonvarCatalogWriteAdmission
{
    private const LOCK_KEY = 'seasonvar-sqlite-catalog-writer';

    public function required(): bool
    {
        return (bool) config(
            'seasonvar.import.writer_admission_enabled',
            false,
        ) && DB::connection()->getDriverName() === 'sqlite';
    }

    public function acquire(int $seconds): ?Lock
    {
        if (! $this->required()) {
            return null;
        }

        $lock = $this->lockStore()->lock(
            self::LOCK_KEY,
            max(60, min(3600, $seconds)),
        );

        return $lock->get() ? $lock : null;
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = Cache::store((string) config(
            'seasonvar.queue.lock_store',
            'redis-locks',
        ));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar writer admission cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar writer admission cache store does not support atomic locks.');
        }

        return $store;
    }
}
