<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly ReviewCacheInvalidator $cache,
    ) {}

    public function handle(User $user, int $reviewId): CatalogTitleReview
    {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);

        if ($this->alreadyDeletedBy($review, $user)) {
            return $review;
        }

        Gate::forUser($user)->authorize('delete', $review);
        $target = $this->targets->fromReview($review, $user);
        $this->rateLimiter->hit('delete', $user, $target->key());

        /** @var array{0: CatalogTitleReview, 1: bool, 2: bool} $result */
        $result = DB::transaction(function () use ($review, $user): array {
            $locked = CatalogTitleReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($this->alreadyDeletedBy($locked, $user)) {
                return [$locked, false, false];
            }

            Gate::forUser($user)->authorize('delete', $locked);
            $wasPublic = $locked->status === ReviewStatus::Published;
            $locked->forceFill([
                'deletion_reason' => ReviewDeletionReason::Author,
                'deleted_by_id' => $user->id,
                'deleted_at' => now(),
                'version' => (int) $locked->version + 1,
            ])->save();

            return [$locked, true, $wasPublic];
        }, attempts: 3);
        [$review, $changed, $wasPublic] = $result;

        if ($changed) {
            $this->cache->titleChanged(
                (int) $review->catalog_title_id,
                recommendations: $wasPublic,
            );
        }

        return $review;
    }

    private function alreadyDeletedBy(CatalogTitleReview $review, User $user): bool
    {
        return $review->origin === ReviewOrigin::User
            && (int) $review->user_id === (int) $user->id
            && $review->merged_into_id === null
            && $review->isDeleted()
            && $review->deletion_reason === ReviewDeletionReason::Author;
    }
}
