<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class HelpCacheInvalidator
{
    public function __construct(private CacheVersionRegistry $versions) {}

    public function changed(?string $articlePublicId = null, bool $sitemap = true): void
    {
        $invalidate = function () use ($articlePublicId, $sitemap): void {
            try {
                $this->versions->bump(CacheDomain::HelpCenter);
                $this->versions->bump(CacheDomain::SearchSuggestions);

                if ($articlePublicId !== null) {
                    $this->versions->bump(CacheDomain::HelpCenter, 'article:'.$articlePublicId);
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
