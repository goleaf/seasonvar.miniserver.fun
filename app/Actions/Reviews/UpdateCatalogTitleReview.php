<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewAntiSpamDecision;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Reviews\ReviewAntiSpamService;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use App\Services\Reviews\VerifiedWatchingService;
use App\ValueObjects\ReviewBody;
use App\ValueObjects\ReviewRating;
use App\ValueObjects\ReviewTitle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpdateCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRestrictionService $restrictions,
        private readonly ReviewAntiSpamService $antiSpam,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly VerifiedWatchingService $verifiedWatching,
        private readonly CatalogUserStateService $userStates,
        private readonly ReviewCacheInvalidator $cache,
    ) {}

    public function handle(
        User $user,
        int $reviewId,
        int $expectedVersion,
        mixed $reviewTitle,
        mixed $body,
        mixed $rating,
        bool $isSpoiler,
    ): CatalogTitleReview {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);
        Gate::forUser($user)->authorize('update', $review);
        $wasPublic = $review->status === ReviewStatus::Published && ! $review->isDeleted();
        $target = $this->targets->fromReview($review, $user);
        $title = $review->catalogTitle()->firstOrFail();
        $normalizedTitle = ReviewTitle::from($reviewTitle);
        $normalizedBody = ReviewBody::from($body);
        $normalizedRating = ReviewRating::from($rating);
        $this->restrictions->assertCanReview($user);
        $this->antiSpam->assertNotCopied($user, $normalizedBody, (int) $review->id);
        $this->rateLimiter->hit('edit_global', $user, 'global');
        $this->rateLimiter->hit('edit', $user, $target->key());

        if ($expectedVersion < 1 || (int) $review->version !== $expectedVersion) {
            throw new ReviewActionException('reviews.errors.stale_edit');
        }

        $requiresReview = $this->antiSpam->decision($user, $normalizedBody) === ReviewAntiSpamDecision::Review;
        $status = $requiresReview ? ReviewStatus::Pending : $review->status;
        $verified = $review->is_verified_watch || $this->verifiedWatching->verified($user, $title);

        DB::transaction(function () use (
            $review,
            $user,
            $title,
            $normalizedTitle,
            $normalizedBody,
            $normalizedRating,
            $isSpoiler,
            $expectedVersion,
            $status,
            $verified,
        ): void {
            $updated = CatalogTitleReview::query()
                ->whereKey($review->id)
                ->where('version', $expectedVersion)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id')
                ->update([
                    'review_title' => $normalizedTitle->value,
                    'body' => $normalizedBody->value,
                    'body_hash' => $normalizedBody->authorScopedHash((int) $user->id),
                    'is_spoiler' => $isSpoiler,
                    'is_verified_watch' => $verified,
                    'status' => $status->value,
                    'version' => $expectedVersion + 1,
                    'edited_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ReviewActionException('reviews.errors.stale_edit');
            }

            $this->userStates->setRating($user, $title, $normalizedRating->value);
        }, attempts: 3);

        $review->refresh();
        $isPublic = $review->status === ReviewStatus::Published && ! $review->isDeleted();
        $this->cache->titleChanged(
            (int) $review->catalog_title_id,
            recommendations: $wasPublic !== $isPublic,
        );

        return $review;
    }
}
