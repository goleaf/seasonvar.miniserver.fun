<?php

declare(strict_types=1);

namespace App\Services\UserPortal;

use App\Jobs\WarmUserPortalCache;
use App\Models\User;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UserPortalCacheInvalidator
{
    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
        private readonly UserPortalCache $cache,
    ) {}

    public function changed(User $user): void
    {
        $publicId = (string) $user->public_id;
        $scope = $this->cache->scope($user);

        $invalidate = function () use ($publicId, $scope): void {
            try {
                $this->versions->bump(CacheDomain::UserPortal, $scope);
                $this->telemetry->increment(CacheDomain::UserPortal, 'invalidation');

                if ((bool) config('cache-architecture.warming.user_portal_enabled', true)) {
                    WarmUserPortalCache::dispatch($publicId);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($invalidate) : $invalidate();
    }
}
