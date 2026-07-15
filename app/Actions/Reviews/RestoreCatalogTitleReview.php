<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RestoreCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRestrictionService $restrictions,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly ReviewCacheInvalidator $cache,
    ) {}

    public function handle(User $user, int $reviewId): CatalogTitleReview
    {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);

        if ($this->alreadyRestoredFor($review, $user)) {
            return $review;
        }

        Gate::forUser($user)->authorize('restore', $review);
        $target = $this->targets->fromReview($review, $user);
        $this->restrictions->assertCanReview($user);
        $this->rateLimiter->hit('restore', $user, $target->key());

        /** @var array{0: CatalogTitleReview, 1: bool} $result */
        $result = DB::transaction(function () use ($review, $user): array {
            $locked = CatalogTitleReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($this->alreadyRestoredFor($locked, $user)) {
                return [$locked, false];
            }

            Gate::forUser($user)->authorize('restore', $locked);
            $this->restrictions->assertCanReview($user);
            $locked->forceFill([
                'deletion_reason' => null,
                'deleted_by_id' => null,
                'deleted_at' => null,
                'version' => (int) $locked->version + 1,
            ])->save();

            return [$locked, true];
        }, attempts: 3);
        [$review, $changed] = $result;

        if ($changed) {
            $this->cache->titleChanged(
                (int) $review->catalog_title_id,
                recommendations: $review->status === ReviewStatus::Published,
            );
        }

        return $review;
    }

    private function alreadyRestoredFor(CatalogTitleReview $review, User $user): bool
    {
        return $review->origin === ReviewOrigin::User
            && (int) $review->user_id === (int) $user->id
            && $review->merged_into_id === null
            && ! $review->isDeleted();
    }
}
