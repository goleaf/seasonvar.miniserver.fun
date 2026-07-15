<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\User;

final class ReviewRestrictionService
{
    public function __construct(private readonly ReviewSchema $schema) {}

    public function activeFor(User $user): ?CatalogTitleReviewRestriction
    {
        if (! $this->schema->writable()) {
            return null;
        }

        return CatalogTitleReviewRestriction::query()
            ->active()
            ->where('user_id', $user->id)
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    public function assertCanReview(User $user): void
    {
        $restriction = $this->activeFor($user);

        if ($restriction === null) {
            return;
        }

        if ($restriction->expires_at === null) {
            throw new ReviewActionException('reviews.errors.permanently_restricted', [
                'reason' => $restriction->reason_code->label(),
            ]);
        }

        throw new ReviewActionException('reviews.errors.temporarily_restricted', [
            'reason' => $restriction->reason_code->label(),
            'expires' => $restriction->expires_at->translatedFormat('d.m.Y H:i'),
        ]);
    }
}
