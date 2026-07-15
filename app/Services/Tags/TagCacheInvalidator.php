<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\User;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\DB;

final readonly class TagCacheInvalidator
{
    public function __construct(
        private CacheVersionRegistry $versions,
        private CatalogCacheInvalidator $catalog,
    ) {}

    /** @param iterable<int, int|string> $titleIds */
    public function publicChanged(iterable $titleIds = []): void
    {
        $ids = collect($titleIds)->all();
        $invalidate = fn () => $this->catalog->catalogChanged($ids);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    public function personalChanged(User $user): void
    {
        $this->personalChangedId((int) $user->getKey());
    }

    public function personalChangedId(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $invalidate = fn () => $this->versions->bump(CacheDomain::Tags, 'user:'.$userId);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }
}
