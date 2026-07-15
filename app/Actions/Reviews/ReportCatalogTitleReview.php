<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\ReviewReportCategory;
use App\Enums\ReviewReportStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewReport;
use App\Models\User;
use App\Services\Reviews\ReviewIdentity;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRelationshipService;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ReportCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewTargetResolver $targets,
        private readonly ReviewRelationshipService $relationships,
        private readonly ReviewRestrictionService $restrictions,
        private readonly ReviewIdentity $identity,
        private readonly ReviewRateLimiter $rateLimiter,
    ) {}

    public function handle(
        User $user,
        int $reviewId,
        ReviewReportCategory|string $category,
        mixed $details,
    ): CatalogTitleReviewReport {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $category = is_string($category) ? ReviewReportCategory::tryFrom($category) : $category;

        if (! $category instanceof ReviewReportCategory) {
            throw new ReviewActionException('reviews.errors.invalid_report_category');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);
        Gate::forUser($user)->authorize('report', $review);
        $this->targets->fromReview($review, $user);
        $this->restrictions->assertCanReview($user);
        $this->relationships->assertCanInteract($user, $review->user_id);
        $details = UserPlainText::description($details);

        if ($details !== null && Str::length($details) > 1_000) {
            throw new ReviewActionException('reviews.errors.report_details_too_long', ['maximum' => 1_000]);
        }

        $deduplicationKey = $this->identity->reportKey(
            (int) $user->id,
            (int) $review->id,
            $category->value,
        );
        $existing = CatalogTitleReviewReport::query()
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->rateLimiter->hit('report', $user, 'review:'.$review->id);

        return DB::transaction(function () use (
            $user,
            $review,
            $category,
            $details,
            $deduplicationKey,
        ): CatalogTitleReviewReport {
            $existing = CatalogTitleReviewReport::query()
                ->where('deduplication_key', $deduplicationKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $timestamp = now();
            CatalogTitleReviewReport::query()->insertOrIgnore([
                'catalog_title_review_id' => $review->id,
                'reporter_id' => $user->id,
                'category' => $category->value,
                'details' => $details,
                'status' => ReviewReportStatus::Open->value,
                'deduplication_key' => $deduplicationKey,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return CatalogTitleReviewReport::query()
                ->where('deduplication_key', $deduplicationKey)
                ->firstOrFail();
        }, attempts: 3);
    }
}
