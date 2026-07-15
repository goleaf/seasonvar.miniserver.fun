<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewVoteType;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewVote;
use App\Models\User;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewNotificationService;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRelationshipService;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SetCatalogTitleReviewVote
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRestrictionService $restrictions,
        private readonly ReviewRelationshipService $relationships,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly ReviewCacheInvalidator $cache,
        private readonly ReviewNotificationService $notifications,
    ) {}

    public function handle(
        User $user,
        int $reviewId,
        ReviewVoteType|string|null $type,
    ): ?CatalogTitleReviewVote {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $submittedType = $type;
        $type = is_string($submittedType) ? ReviewVoteType::tryFrom($submittedType) : $submittedType;

        if (is_string($submittedType) && ! $type instanceof ReviewVoteType) {
            throw new ReviewActionException('reviews.errors.invalid_vote');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);
        Gate::forUser($user)->authorize('vote', $review);
        $target = $this->targets->fromReview($review, $user);
        $this->restrictions->assertCanReview($user);
        $this->relationships->assertCanInteract($user, $review->user_id);
        $this->rateLimiter->hit('vote_global', $user, 'global');
        $this->rateLimiter->hit('vote', $user, 'review:'.$review->id);

        $vote = DB::transaction(function () use ($review, $user, $type): ?CatalogTitleReviewVote {
            if ($type === null) {
                CatalogTitleReviewVote::query()
                    ->where('catalog_title_review_id', $review->id)
                    ->where('user_id', $user->id)
                    ->delete();

                return null;
            }

            $timestamp = now();
            CatalogTitleReviewVote::query()->upsert(
                [[
                    'catalog_title_review_id' => $review->id,
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
                ['catalog_title_review_id', 'user_id'],
                ['type', 'updated_at'],
            );

            return CatalogTitleReviewVote::query()
                ->where('catalog_title_review_id', $review->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
        }, attempts: 3);

        $this->cache->titleChanged($target->catalogTitleId);
        $this->notifications->voteChanged($vote, $review, $user);

        return $vote;
    }
}
