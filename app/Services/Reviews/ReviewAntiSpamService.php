<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\ReviewAntiSpamDecision;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\ValueObjects\ReviewBody;

final class ReviewAntiSpamService
{
    public function assertNotCopied(
        User $user,
        ReviewBody $body,
        ?int $exceptReviewId = null,
    ): void {
        $days = max(1, (int) config('reviews.anti_spam.duplicate_body_window_days', 7));
        $duplicate = CatalogTitleReview::query()
            ->where('user_id', $user->id)
            ->where('body_hash', $body->authorScopedHash((int) $user->id))
            ->where('created_at', '>=', now()->subDays($days))
            ->when($exceptReviewId !== null, fn ($query) => $query->whereKeyNot($exceptReviewId))
            ->exists();

        if ($duplicate) {
            throw new ReviewActionException('reviews.errors.duplicate_content');
        }
    }

    public function decision(User $user, ReviewBody $body): ReviewAntiSpamDecision
    {
        $minutes = max(0, (int) config('reviews.anti_spam.new_account_review_minutes', 60));
        $isNewAccount = $minutes > 0
            && $user->created_at !== null
            && $user->created_at->isAfter(now()->subMinutes($minutes));

        return $isNewAccount || $body->linkCount > 0
            ? ReviewAntiSpamDecision::Review
            : ReviewAntiSpamDecision::Allow;
    }
}
