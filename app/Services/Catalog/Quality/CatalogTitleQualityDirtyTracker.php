<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\Models\CatalogTitleQualitySnapshot;
use Illuminate\Support\Facades\Schema;

final class CatalogTitleQualityDirtyTracker
{
    /** @param iterable<int, int|string> $titleIds */
    public function mark(iterable $titleIds): int
    {
        if (! Schema::hasTable('catalog_title_quality_snapshots')) {
            return 0;
        }

        $ids = collect($titleIds)
            ->filter(static fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(1_000)
            ->all();

        if ($ids === []) {
            return 0;
        }

        return CatalogTitleQualitySnapshot::query()
            ->whereKey($ids)
            ->where('needs_refresh', false)
            ->update(['needs_refresh' => true]);
    }
}
