<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogRecommendationDirtyTitle;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CatalogRecommendationDirtyTitleTracker
{
    private ?bool $available = null;

    public function mark(int $titleId, string $reason): void
    {
        $this->markMany([$titleId], $reason);
    }

    /** @param iterable<int, int|string> $titleIds */
    public function markMany(iterable $titleIds, string $reason): void
    {
        if (! $this->available()) {
            return;
        }

        $now = now();
        $reason = Str::substr(Str::slug($reason) ?: 'catalog-change', 0, 64);
        $rows = collect($titleIds)
            ->filter(fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->map(fn (int $id): array => [
                'catalog_title_id' => $id,
                'reason' => $reason,
                'marked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        foreach ($rows->chunk(1000) as $chunk) {
            CatalogRecommendationDirtyTitle::query()->upsert(
                $chunk->all(),
                ['catalog_title_id'],
                ['reason', 'marked_at', 'updated_at'],
            );
        }
    }

    /** @return list<int> */
    public function ids(int $limit): array
    {
        if ($limit <= 0 || ! $this->available()) {
            return [];
        }

        return CatalogRecommendationDirtyTitle::query()
            ->orderBy('marked_at')
            ->orderBy('id')
            ->limit(min(100_000, $limit))
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @param list<int> $titleIds */
    public function forget(array $titleIds): void
    {
        if (! $this->available()) {
            return;
        }

        $titleIds = array_values(array_unique(array_filter(
            array_map('intval', $titleIds),
            fn (int $id): bool => $id > 0,
        )));

        foreach (array_chunk($titleIds, 1_000) as $chunk) {
            CatalogRecommendationDirtyTitle::query()
                ->whereIn('catalog_title_id', $chunk)
                ->delete();
        }
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable('catalog_recommendation_dirty_titles');
    }
}
