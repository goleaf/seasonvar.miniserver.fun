<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\DTOs\Reviews\ReviewViewerContext;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\User;
use App\Services\Comments\CommentRelationshipService;
use Illuminate\Support\Facades\Gate;

final class ReviewRelationshipService
{
    public function __construct(
        private readonly CommentRelationshipService $relationships,
        private readonly ReviewSchema $schema,
    ) {}

    /** @param list<int>|null $relevantUserIds */
    public function context(?User $viewer, ?array $relevantUserIds = null): ReviewViewerContext
    {
        if ($viewer !== null && ! $this->schema->writable()) {
            return new ReviewViewerContext(
                userId: (int) $viewer->id,
                isModerator: Gate::forUser($viewer)->allows('manage-reviews'),
                isReviewRestricted: false,
                blockedUserIds: [],
                mutedUserIds: [],
            );
        }

        $context = $this->relationships->context($viewer, $relevantUserIds);

        return new ReviewViewerContext(
            userId: $context->userId,
            isModerator: $viewer !== null && Gate::forUser($viewer)->allows('manage-reviews'),
            isReviewRestricted: false,
            blockedUserIds: $context->blockedUserIds,
            mutedUserIds: $context->mutedUserIds,
        );
    }

    public function assertCanInteract(User $actor, ?int $otherUserId): void
    {
        if ($otherUserId === null || $otherUserId === (int) $actor->id) {
            return;
        }

        if ($this->relationships->isBlockedBetween((int) $actor->id, $otherUserId)) {
            throw new ReviewActionException('reviews.errors.interaction_blocked');
        }
    }

    public function shouldNotify(User $recipient, User $actor): bool
    {
        return $this->relationships->shouldNotify($recipient, $actor);
    }
}
