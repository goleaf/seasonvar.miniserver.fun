<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Models\User;
use Illuminate\Support\Str;

final class ReviewIdentity
{
    public function ownershipKey(User|int $user, int $catalogTitleId): string
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        return hash('sha256', 'review-owner:'.$userId.':title:'.$catalogTitleId);
    }

    public function submissionKey(
        User|int $user,
        int $catalogTitleId,
        string $submissionToken,
    ): string {
        if (! Str::isUuid($submissionToken)) {
            throw new ReviewActionException('reviews.errors.invalid_submission');
        }

        $userId = $user instanceof User ? (int) $user->id : $user;

        return hash('sha256', implode(':', [
            'review-submission',
            $userId,
            $catalogTitleId,
            Str::lower($submissionToken),
        ]));
    }

    public function reportKey(int $userId, int $reviewId, string $category): string
    {
        return hash('sha256', implode(':', [
            'review-report',
            $userId,
            $reviewId,
            $category,
        ]));
    }
}
