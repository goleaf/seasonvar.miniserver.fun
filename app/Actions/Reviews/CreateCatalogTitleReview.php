<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewAntiSpamDecision;
use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Reviews\ReviewAntiSpamService;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewIdentity;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use App\Services\Reviews\VerifiedWatchingService;
use App\ValueObjects\ReviewBody;
use App\ValueObjects\ReviewRating;
use App\ValueObjects\ReviewTitle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRestrictionService $restrictions,
        private readonly ReviewAntiSpamService $antiSpam,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly ReviewIdentity $identity,
        private readonly VerifiedWatchingService $verifiedWatching,
        private readonly CatalogUserStateService $userStates,
        private readonly ReviewCacheInvalidator $cache,
    ) {}

    public function handle(
        User $user,
        int $catalogTitleId,
        mixed $reviewTitle,
        mixed $body,
        mixed $rating,
        bool $isSpoiler,
        string $submissionToken,
    ): CatalogTitleReview {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        Gate::forUser($user)->authorize('create', CatalogTitleReview::class);
        $target = $this->targets->resolve(ReviewTargetType::Title, $catalogTitleId, $user);
        $title = CatalogTitle::query()->findOrFail($target->catalogTitleId);
        Gate::forUser($user)->authorize('interact', $title);
        $normalizedTitle = ReviewTitle::from($reviewTitle);
        $normalizedBody = ReviewBody::from($body);
        $normalizedRating = ReviewRating::from($rating);
        $submissionKey = $this->identity->submissionKey($user, $target->catalogTitleId, $submissionToken);
        $ownershipKey = $this->identity->ownershipKey($user, $target->catalogTitleId);
        $existingSubmission = CatalogTitleReview::query()->where('submission_key', $submissionKey)->first();

        if ($existingSubmission !== null) {
            return $existingSubmission;
        }

        $this->assertNoExistingReview($ownershipKey);
        $this->restrictions->assertCanReview($user);
        $this->antiSpam->assertNotCopied($user, $normalizedBody);
        $this->rateLimiter->hit('create', $user, $target->key());
        $status = $this->antiSpam->decision($user, $normalizedBody) === ReviewAntiSpamDecision::Review
            ? ReviewStatus::Pending
            : ReviewStatus::Published;
        $verified = $this->verifiedWatching->verified($user, $title);

        try {
            $review = DB::transaction(function () use (
                $user,
                $title,
                $normalizedTitle,
                $normalizedBody,
                $normalizedRating,
                $isSpoiler,
                $submissionKey,
                $ownershipKey,
                $status,
                $verified,
            ): CatalogTitleReview {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existingSubmission = CatalogTitleReview::query()->where('submission_key', $submissionKey)->first();

                if ($existingSubmission !== null) {
                    return $existingSubmission;
                }

                $this->restrictions->assertCanReview($user);
                $this->releaseExpiredOwnership($ownershipKey);
                $this->assertNoExistingReview($ownershipKey);
                $review = CatalogTitleReview::query()->create([
                    'catalog_title_id' => $title->id,
                    'user_id' => $user->id,
                    'origin' => ReviewOrigin::User,
                    'review_title' => $normalizedTitle->value,
                    'body' => $normalizedBody->value,
                    'body_hash' => $normalizedBody->authorScopedHash((int) $user->id),
                    'is_spoiler' => $isSpoiler,
                    'is_verified_watch' => $verified,
                    'status' => $status,
                    'version' => 1,
                    'ownership_key' => $ownershipKey,
                    'submission_key' => $submissionKey,
                    'published_at' => $status === ReviewStatus::Published ? now() : null,
                ]);
                if ($normalizedRating->value !== null) {
                    $this->userStates->setRating($user, $title, $normalizedRating->value);
                }

                return $review;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existingSubmission = CatalogTitleReview::query()
                ->where('submission_key', $submissionKey)
                ->first();

            if ($existingSubmission !== null) {
                return $existingSubmission;
            }

            $existingReview = CatalogTitleReview::query()
                ->where('ownership_key', $ownershipKey)
                ->first();

            if ($existingReview !== null) {
                throw new ReviewActionException('reviews.errors.duplicate_review', [
                    'review' => (int) $existingReview->id,
                ]);
            }

            throw $exception;
        }

        $this->cache->titleChanged(
            (int) $title->id,
            recommendations: $review->status === ReviewStatus::Published,
        );

        return $review;
    }

    private function assertNoExistingReview(string $ownershipKey): void
    {
        $existing = CatalogTitleReview::query()->where('ownership_key', $ownershipKey)->first();

        if ($existing !== null && ! $this->canReleaseOwnership($existing)) {
            throw new ReviewActionException('reviews.errors.duplicate_review', [
                'review' => (int) $existing->id,
            ]);
        }
    }

    private function releaseExpiredOwnership(string $ownershipKey): void
    {
        $existing = CatalogTitleReview::query()
            ->where('ownership_key', $ownershipKey)
            ->first();

        if (! $existing instanceof CatalogTitleReview || ! $this->canReleaseOwnership($existing)) {
            return;
        }

        $existing->forceFill([
            'original_body_hash' => $existing->original_body_hash ?? $existing->body_hash,
            'body_hash' => hash(
                'sha256',
                'released-review:'.$existing->id.':'.$existing->body_hash,
            ),
            'ownership_key' => null,
            'submission_key' => null,
            'ownership_released_at' => now(),
            'version' => (int) $existing->version + 1,
        ])->save();
    }

    private function canReleaseOwnership(CatalogTitleReview $review): bool
    {
        $days = max(1, (int) config('reviews.editing.restoration_days', 30));

        return $review->isDeleted()
            && $review->merged_into_id === null
            && $review->deletion_reason === ReviewDeletionReason::Author
            && $review->deleted_at?->lessThanOrEqualTo(now()->subDays($days)) === true;
    }
}
