<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentTargetType;
use App\Models\Comment;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationCacheInvalidator;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheTelemetry;
use App\Support\Cache\CacheVersionRegistry;
use App\ValueObjects\CommentTarget;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CommentCacheInvalidator
{
    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly CacheTelemetry $telemetry,
        private readonly CatalogRecommendationCacheInvalidator $recommendations,
    ) {}

    public function targetChanged(CommentTarget $target): void
    {
        $invalidate = function () use ($target): void {
            $this->invalidate($target->type, $target->catalogTitleId);

            if ($target->type === CommentTargetType::Title && $target->catalogTitleId !== null) {
                $this->recommendations->publicSignalsChanged('comment-change');
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    public function commentChanged(Comment $comment): void
    {
        $catalogTitleIds = $comment->catalog_title_id !== null
            ? [(int) $comment->catalog_title_id]
            : [];

        $this->identitiesChanged(
            $catalogTitleIds,
            $comment->target_type === CommentTargetType::Collection,
        );
    }

    public function authorChanged(User $user): void
    {
        $titleIds = Comment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->whereNotNull('catalog_title_id')
            ->distinct()
            ->limit(1_001)
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $hasCollections = Comment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('target_type', CommentTargetType::Collection->value)
            ->exists();

        $this->identitiesChanged($titleIds, $hasCollections);
    }

    /** @param iterable<int, int|string> $catalogTitleIds */
    public function identitiesChanged(iterable $catalogTitleIds, bool $collections): void
    {
        $titleIds = collect($catalogTitleIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $invalidateAllTitles = $titleIds->count() > 1_000;
        $scopedTitleIds = $invalidateAllTitles ? [] : $titleIds->all();
        $invalidate = function () use ($scopedTitleIds, $invalidateAllTitles, $collections): void {
            if ($invalidateAllTitles) {
                $this->invalidateAllTitlePages();
            } else {
                foreach ($scopedTitleIds as $titleId) {
                    $this->invalidate(CommentTargetType::Title, $titleId);
                }
            }

            if ($collections) {
                $this->invalidate(CommentTargetType::Collection, null);
            }

            if ($invalidateAllTitles || $scopedTitleIds !== []) {
                $this->recommendations->publicSignalsChanged('comment-change');
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    private function invalidateAllTitlePages(): void
    {
        try {
            $this->versions->bump(CacheDomain::TitleDetail);
            $this->telemetry->increment(CacheDomain::TitleDetail, 'comment-author-invalidation');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function invalidate(CommentTargetType $type, ?int $catalogTitleId): void
    {
        try {
            if ($catalogTitleId !== null) {
                $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$catalogTitleId);
                $this->telemetry->increment(CacheDomain::TitleDetail, 'comment-invalidation');
            }

            if ($type === CommentTargetType::Collection) {
                $this->versions->bump(CacheDomain::Collections);
                $this->telemetry->increment(CacheDomain::Collections, 'comment-invalidation');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
