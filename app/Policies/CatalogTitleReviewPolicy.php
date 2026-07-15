<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitleReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CatalogTitleReviewPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return Gate::forUser($user)->allows('manage-reviews')
            && in_array($ability, ['view', 'moderate'], true)
                ? true
                : null;
    }

    public function view(?User $user, CatalogTitleReview $review): bool
    {
        if ($review->status === ReviewStatus::Published
            && ! $review->isDeleted()
            && $review->merged_into_id === null) {
            return true;
        }

        return $user !== null && $review->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return (bool) config('reviews.enabled', true) && $user->hasVerifiedEmail();
    }

    public function update(User $user, CatalogTitleReview $review): bool
    {
        return (bool) config('reviews.enabled', true)
            && $review->origin === ReviewOrigin::User
            && $review->user_id === $user->id
            && ! $review->isDeleted()
            && $review->merged_into_id === null
            && in_array($review->status, [ReviewStatus::Published, ReviewStatus::Pending], true);
    }

    public function delete(User $user, CatalogTitleReview $review): bool
    {
        return $review->origin === ReviewOrigin::User
            && $review->user_id === $user->id
            && ! $review->isDeleted()
            && $review->merged_into_id === null;
    }

    public function restore(User $user, CatalogTitleReview $review): bool
    {
        $days = max(1, (int) config('reviews.editing.restoration_days', 30));

        return (bool) config('reviews.enabled', true)
            && $review->origin === ReviewOrigin::User
            && $review->user_id === $user->id
            && $review->isDeleted()
            && $review->merged_into_id === null
            && $review->ownership_key !== null
            && $review->deletion_reason === ReviewDeletionReason::Author
            && $review->deleted_at?->isAfter(now()->subDays($days)) === true;
    }

    public function vote(User $user, CatalogTitleReview $review): bool
    {
        return (bool) config('reviews.enabled', true)
            && $user->hasVerifiedEmail()
            && $review->user_id !== $user->id
            && $review->status === ReviewStatus::Published
            && ! $review->isDeleted()
            && $review->merged_into_id === null;
    }

    public function report(User $user, CatalogTitleReview $review): bool
    {
        return (bool) config('reviews.enabled', true)
            && $user->hasVerifiedEmail()
            && $review->user_id !== $user->id
            && $review->status === ReviewStatus::Published
            && ! $review->isDeleted()
            && $review->merged_into_id === null;
    }

    public function moderate(User $user, CatalogTitleReview $review): bool
    {
        return Gate::forUser($user)->allows('manage-reviews');
    }
}
