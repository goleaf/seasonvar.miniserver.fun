<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;

final readonly class ReleaseCalendarCacheInvalidator
{
    public function __construct(private CacheVersionRegistry $versions) {}

    public function scheduleChanged(?int $catalogTitleId = null): void
    {
        $invalidate = function () use ($catalogTitleId): void {
            $this->versions->bump(CacheDomain::ReleaseCalendar);
            $this->versions->bump(CacheDomain::Homepage);
            $this->versions->bump(CacheDomain::Sitemap);

            if ($catalogTitleId !== null) {
                $this->versions->bump(CacheDomain::ReleaseCalendar, 'title:'.$catalogTitleId);
                $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$catalogTitleId);
            }
        };

        $this->afterCommit($invalidate);
    }

    public function userChanged(int $userId): void
    {
        $this->afterCommit(fn () => $this->versions->bump(CacheDomain::ReleaseCalendar, 'user:'.$userId));
    }

    private function afterCommit(callable $invalidate): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }
}
