<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentDeletionReason;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Models\CatalogCollection;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;

final class CommentTargetLifecycleService
{
    public function __construct(
        private readonly CommentSchema $schema,
        private readonly CommentCacheInvalidator $cache,
    ) {}

    public function retireCollection(CatalogCollection $collection): void
    {
        $this->retireCollections([$collection->id]);
    }

    /** @param iterable<int, int|string> $collectionIds */
    public function retireCollections(iterable $collectionIds): void
    {
        if (! $this->schema->available()) {
            return;
        }

        $ids = collect($collectionIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        Comment::query()
            ->withTrashed()
            ->where('target_type', CommentTargetType::Collection->value)
            ->whereIn('target_id', $ids)
            ->update([
                'status' => CommentStatus::Removed->value,
                'deletion_reason' => CommentDeletionReason::Privacy->value,
                'deleted_by_id' => null,
                'version' => DB::raw('version + 1'),
                'deleted_at' => DB::raw('COALESCE(deleted_at, CURRENT_TIMESTAMP)'),
                'updated_at' => now(),
            ]);

        $this->cache->identitiesChanged([], true);
    }
}
