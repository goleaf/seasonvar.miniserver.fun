<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final readonly class ReleaseCalendarCacheInvalidator
{
    private const DEFER_PUBLIC_INVALIDATION_CONTEXT = 'seasonvar.import.defer-release-calendar-public-invalidation';

    public function __construct(private CacheVersionRegistry $versions) {}

    public function scheduleChanged(?int $catalogTitleId = null): void
    {
        if (Context::getHidden(self::DEFER_PUBLIC_INVALIDATION_CONTEXT, false) === true) {
            return;
        }

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

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function deferPublicInvalidation(callable $callback): mixed
    {
        return Context::scope(
            $callback,
            hidden: [self::DEFER_PUBLIC_INVALIDATION_CONTEXT => true],
        );
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
