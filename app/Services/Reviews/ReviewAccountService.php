<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewAlias;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\CatalogTitleReviewVote;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Support\DeterministicUuid;

final class ReviewAccountService
{
    public function __construct(
        private readonly ReviewCacheInvalidator $cache,
        private readonly ReviewSchema $schema,
    ) {}

    /** @return array{reviews: list<array<string, mixed>>, votes: list<array<string, mixed>>} */
    public function export(User $user): array
    {
        if (! $this->schema->communityAvailable()) {
            return ['reviews' => [], 'votes' => []];
        }

        $reviews = CatalogTitleReview::query()
            ->where('user_id', $user->id)
            ->with([
                'catalogTitle' => fn ($query) => $query
                    ->withTrashed()
                    ->select(['id', 'slug', 'title']),
            ])
            ->orderBy('id')
            ->get();
        $ratings = CatalogTitleUserState::query()
            ->where('user_id', $user->id)
            ->whereIn('catalog_title_id', $reviews->pluck('catalog_title_id')->unique())
            ->pluck('rating', 'catalog_title_id');
        $reviewExport = $reviews->map(fn (CatalogTitleReview $review): array => [
            'review_id' => (int) $review->id,
            'target_type' => 'title',
            'target_id' => (int) $review->catalog_title_id,
            'target_slug' => $review->catalogTitle?->slug,
            'target_title' => $review->catalogTitle?->title,
            'title' => $review->review_title,
            'body' => $review->body,
            'rating' => $ratings->get($review->catalog_title_id),
            'contains_spoiler' => $review->is_spoiler,
            'verified_watching' => $review->is_verified_watch,
            'public_status' => $review->status->value,
            'status_before_merge' => $review->status_before_merge,
            'deletion_reason_before_merge' => $review->deletion_reason_before_merge,
            'ownership_released_at' => $review->ownership_released_at?->toAtomString(),
            'version' => $review->version,
            'published_at' => $review->published_at?->toAtomString(),
            'edited_at' => $review->edited_at?->toAtomString(),
            'created_at' => $review->created_at?->toAtomString(),
            'updated_at' => $review->updated_at?->toAtomString(),
            'deleted_at' => $review->deleted_at?->toAtomString(),
        ])->all();
        $votes = $this->schema->writable()
            ? CatalogTitleReviewVote::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['catalog_title_review_id', 'type', 'created_at', 'updated_at'])
                ->map(fn (CatalogTitleReviewVote $vote): array => [
                    'review_id' => (int) $vote->catalog_title_review_id,
                    'vote' => $vote->type->value,
                    'created_at' => $vote->created_at?->toAtomString(),
                    'updated_at' => $vote->updated_at?->toAtomString(),
                ])->all()
            : [];

        return ['reviews' => $reviewExport, 'votes' => $votes];
    }

    public function prepareForDeletion(User $user): void
    {
        if (! $this->schema->communityAvailable()) {
            return;
        }

        $titleIds = CatalogTitleReview::query()
            ->where('user_id', $user->id)
            ->pluck('catalog_title_id');

        if ($this->schema->writable()) {
            $votedTitleIds = CatalogTitleReviewVote::query()
                ->where('catalog_title_review_votes.user_id', $user->id)
                ->join(
                    'catalog_title_reviews',
                    'catalog_title_reviews.id',
                    '=',
                    'catalog_title_review_votes.catalog_title_review_id',
                )
                ->pluck('catalog_title_reviews.catalog_title_id');
            $titleIds = $titleIds->merge($votedTitleIds);
            $this->removeVoteNotifications($user);
            CatalogTitleReviewVote::query()->where('user_id', $user->id)->delete();
            CatalogTitleReviewReport::query()->where('reporter_id', $user->id)->update([
                'reporter_id' => null,
                'deduplication_key' => null,
                'updated_at' => now(),
            ]);
            CatalogTitleReviewRestriction::query()->where('user_id', $user->id)->delete();
            CatalogTitleReviewNotificationPreference::query()->where('user_id', $user->id)->delete();
        }

        CatalogTitleReview::query()
            ->where('user_id', $user->id)
            ->eachById(function (CatalogTitleReview $review): void {
                $review->forceFill([
                    'user_id' => null,
                    'author' => null,
                    'body_hash' => bin2hex(random_bytes(32)),
                    'original_body_hash' => null,
                    'ownership_key' => null,
                    'submission_key' => null,
                    'ownership_released_at' => now(),
                    'updated_at' => now(),
                ])->save();
            });

        if ($this->schema->notificationsAvailable()) {
            $user->notifications()->where('type', 'review.activity')->delete();
        }

        $this->cache->titlesChanged($titleIds);
    }

    public function authorIdentityChanged(User $user): void
    {
        if (! $this->schema->communityAvailable()) {
            return;
        }

        $this->cache->titlesChanged(
            CatalogTitleReview::query()
                ->where('user_id', $user->id)
                ->pluck('catalog_title_id'),
        );
    }

    private function removeVoteNotifications(User $actor): void
    {
        if (! $this->schema->notificationsAvailable()) {
            return;
        }

        CatalogTitleReviewVote::query()
            ->where('user_id', $actor->id)
            ->with(['review.authorAccount:id'])
            ->chunkById(200, function ($votes) use ($actor): void {
                foreach ($votes as $vote) {
                    $recipient = $vote->review?->authorAccount;

                    if (! $recipient instanceof User) {
                        continue;
                    }

                    $id = DeterministicUuid::from(
                        'seasonvar.review.notification',
                        $recipient->id.':helpful:'.$vote->catalog_title_review_id.':'.$actor->id,
                    );
                    $legacyIds = CatalogTitleReviewAlias::query()
                        ->where('canonical_review_id', $vote->catalog_title_review_id)
                        ->pluck('legacy_review_id')
                        ->map(fn (mixed $reviewId): string => DeterministicUuid::from(
                            'seasonvar.review.notification',
                            $recipient->id.':helpful:'.$reviewId.':'.$actor->id,
                        ))
                        ->all();
                    $recipient->notifications()->whereKey([$id, ...$legacyIds])->delete();
                }
            });
    }
}
