<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ContentRequestCacheInvalidator
{
    public function __construct(private CacheVersionRegistry $versions) {}

    public function changed(?string $publicId = null, bool $sitemap = false): void
    {
        $invalidate = function () use ($publicId, $sitemap): void {
            try {
                $this->versions->bump(CacheDomain::ContentRequests);

                if ($publicId !== null) {
                    $this->versions->bump(CacheDomain::ContentRequests, 'request:'.$publicId);
                }

                if ($sitemap) {
                    $this->versions->bump(CacheDomain::Sitemap);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($invalidate) : $invalidate();
    }
}
