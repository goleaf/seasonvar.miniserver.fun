<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewReportStatus;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewAlias;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewVote;
use App\Models\User;
use App\Support\DeterministicUuid;

final class ReviewMergeService
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewIdentity $identity,
        private readonly ReviewCacheInvalidator $cache,
    ) {}

    public function merge(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        if (! $this->schema->legacyAvailable() || $canonical->is($duplicate)) {
            return;
        }

        CatalogTitleReview::query()
            ->where('catalog_title_id', $duplicate->id)
            ->eachById(function (CatalogTitleReview $review) use ($canonical, $duplicate): void {
                if (! $this->schema->communityAvailable()) {
                    $this->mergeLegacyReview($review, $canonical);

                    return;
                }

                if ($review->merged_into_id !== null) {
                    $review->forceFill(['catalog_title_id' => $canonical->id])->save();

                    return;
                }

                $sameContent = CatalogTitleReview::query()
                    ->where('catalog_title_id', $canonical->id)
                    ->where('body_hash', $review->body_hash)
                    ->whereKeyNot($review->id)
                    ->whereNull('merged_into_id')
                    ->orderByRaw(
                        'CASE WHEN deleted_at IS NULL AND status = ? THEN 0 WHEN deleted_at IS NULL THEN 1 ELSE 2 END',
                        [ReviewStatus::Published->value],
                    )
                    ->orderBy('id')
                    ->first();

                if ($sameContent instanceof CatalogTitleReview) {
                    if ($this->preferIncoming($review, $sameContent)) {
                        $this->mergeEngagement($sameContent, $review);
                        $this->rememberAlias($sameContent, $review, $canonical);
                        $this->archiveMergedReview($sameContent, $review, $canonical);
                        $this->moveReviewToTitle($review, $canonical);

                        return;
                    }

                    $this->mergeEngagement($review, $sameContent);
                    $this->rememberAlias($review, $sameContent, $duplicate);
                    $this->archiveMergedReview($review, $sameContent, $canonical);

                    return;
                }

                $sameAuthor = $review->user_id !== null
                    ? CatalogTitleReview::query()
                        ->where('catalog_title_id', $canonical->id)
                        ->where('user_id', $review->user_id)
                        ->whereNull('merged_into_id')
                        ->orderByRaw(
                            'CASE WHEN ownership_key IS NOT NULL THEN 0 WHEN deleted_at IS NULL AND status = ? THEN 1 WHEN deleted_at IS NULL THEN 2 ELSE 3 END',
                            [ReviewStatus::Published->value],
                        )
                        ->orderBy('id')
                        ->first()
                    : null;

                if ($sameAuthor instanceof CatalogTitleReview) {
                    if ($this->preferIncoming($review, $sameAuthor)) {
                        $this->mergeEngagement($sameAuthor, $review);
                        $this->rememberAlias($sameAuthor, $review, $canonical);
                        $this->archiveMergedReview($sameAuthor, $review, $canonical);
                        $this->moveReviewToTitle($review, $canonical);

                        return;
                    }

                    $this->mergeEngagement($review, $sameAuthor);
                    $this->rememberAlias($review, $sameAuthor, $duplicate);
                    $this->archiveMergedReview($review, $sameAuthor, $canonical);

                    return;
                }

                $this->moveReviewToTitle($review, $canonical);
            });

        $this->cache->titlesChanged(
            [$canonical->id, $duplicate->id],
            recommendations: true,
            api: true,
        );
    }

    private function mergeLegacyReview(CatalogTitleReview $review, CatalogTitle $canonical): void
    {
        $collision = CatalogTitleReview::query()
            ->where('catalog_title_id', $canonical->id)
            ->where('body_hash', $review->body_hash)
            ->whereKeyNot($review->id)
            ->exists();

        $review->forceFill([
            'catalog_title_id' => $canonical->id,
            'body_hash' => $collision
                ? hash('sha256', 'legacy-title-merge:'.$review->id.':'.$review->body_hash)
                : $review->body_hash,
        ])->save();
    }

    private function archiveMergedReview(
        CatalogTitleReview $review,
        CatalogTitleReview $canonicalReview,
        CatalogTitle $canonicalTitle,
    ): void {
        $updates = [
            'catalog_title_id' => $canonicalTitle->id,
            'status_before_merge' => $review->status_before_merge ?? $review->status->value,
            'deletion_reason_before_merge' => $review->deletion_reason_before_merge
                ?? $review->deletion_reason?->value,
            'status' => ReviewStatus::Removed,
            'deletion_reason' => ReviewDeletionReason::Merged,
            'deleted_at' => $review->deleted_at ?? now(),
            'ownership_key' => null,
            'submission_key' => null,
            'ownership_released_at' => $review->ownership_released_at ?? now(),
            'merged_into_id' => $canonicalReview->id,
            'version' => (int) $review->version + 1,
            'original_body_hash' => $review->original_body_hash ?? $review->body_hash,
            'body_hash' => hash(
                'sha256',
                'merged-review:'.$review->id.':'.$review->body_hash,
            ),
        ];

        $review->forceFill($updates)->save();
    }

    private function moveReviewToTitle(
        CatalogTitleReview $review,
        CatalogTitle $canonicalTitle,
    ): void {
        $updates = ['catalog_title_id' => $canonicalTitle->id];

        if ($review->origin === ReviewOrigin::User
            && $review->user_id !== null
            && $review->ownership_key !== null) {
            $updates['ownership_key'] = $this->identity->ownershipKey(
                (int) $review->user_id,
                (int) $canonicalTitle->id,
            );
        }

        $review->forceFill($updates)->save();
    }

    private function preferIncoming(
        CatalogTitleReview $incoming,
        CatalogTitleReview $existing,
    ): bool {
        $incomingPriority = $this->mergePriority($incoming);
        $existingPriority = $this->mergePriority($existing);

        if ($incomingPriority !== $existingPriority) {
            return $incomingPriority < $existingPriority;
        }

        $incomingTimestamp = $incoming->edited_at ?? $incoming->updated_at ?? $incoming->created_at;
        $existingTimestamp = $existing->edited_at ?? $existing->updated_at ?? $existing->created_at;

        if ($incomingTimestamp !== null && $existingTimestamp !== null
            && ! $incomingTimestamp->equalTo($existingTimestamp)) {
            return $incomingTimestamp->isAfter($existingTimestamp);
        }

        $incomingLength = mb_strlen((string) $incoming->body);
        $existingLength = mb_strlen((string) $existing->body);

        return $incomingLength !== $existingLength
            ? $incomingLength > $existingLength
            : (int) $incoming->id < (int) $existing->id;
    }

    private function mergePriority(CatalogTitleReview $review): int
    {
        if ($review->merged_into_id !== null || $review->deleted_at !== null) {
            return 50;
        }

        return match ($review->status) {
            ReviewStatus::Published => 0,
            ReviewStatus::Pending => 10,
            ReviewStatus::Hidden => 20,
            ReviewStatus::Rejected => 30,
            ReviewStatus::Spam, ReviewStatus::Removed => 40,
        };
    }

    private function mergeEngagement(
        CatalogTitleReview $legacy,
        CatalogTitleReview $canonical,
    ): void {
        if (! $this->schema->writable()) {
            return;
        }

        $safetyUpdates = [];

        if ($legacy->is_verified_watch && ! $canonical->is_verified_watch) {
            $safetyUpdates['is_verified_watch'] = true;
        }

        if ($legacy->is_spoiler && ! $canonical->is_spoiler) {
            $safetyUpdates['is_spoiler'] = true;
        }

        if ($safetyUpdates !== []) {
            $canonical->forceFill([
                ...$safetyUpdates,
                'version' => (int) $canonical->version + 1,
            ])->save();
        }

        CatalogTitleReviewVote::query()
            ->where('catalog_title_review_id', $legacy->id)
            ->eachById(function (CatalogTitleReviewVote $vote) use ($canonical, $legacy): void {
                $duplicateVote = CatalogTitleReviewVote::query()
                    ->where('catalog_title_review_id', $canonical->id)
                    ->where('user_id', $vote->user_id)
                    ->exists();

                if ($duplicateVote || (int) $canonical->user_id === (int) $vote->user_id) {
                    $this->removeVoteNotification($legacy, $vote);
                    $vote->delete();

                    return;
                }

                $vote->forceFill(['catalog_title_review_id' => $canonical->id])->save();
            });
        CatalogTitleReviewReport::query()
            ->where('catalog_title_review_id', $legacy->id)
            ->eachById(function (CatalogTitleReviewReport $report) use ($canonical): void {
                $deduplicationKey = null;
                $duplicateExists = false;

                if ($report->reporter_id !== null && $report->deduplication_key !== null) {
                    $candidate = $this->identity->reportKey(
                        (int) $report->reporter_id,
                        (int) $canonical->id,
                        $report->category->value,
                    );
                    $duplicateExists = CatalogTitleReviewReport::query()
                        ->where('deduplication_key', $candidate)
                        ->whereKeyNot($report->id)
                        ->exists();
                    $deduplicationKey = $duplicateExists ? null : $candidate;
                }

                $updates = [
                    'catalog_title_review_id' => $canonical->id,
                    'deduplication_key' => $deduplicationKey,
                    'updated_at' => now(),
                ];

                if ($duplicateExists) {
                    $updates['status'] = ReviewReportStatus::Dismissed;
                    $updates['resolved_at'] = $report->resolved_at ?? now();
                }

                $report->forceFill($updates)->save();
            });
    }

    private function rememberAlias(
        CatalogTitleReview $legacy,
        CatalogTitleReview $canonical,
        CatalogTitle $legacyTitle,
    ): void {
        if (! $this->schema->writable()) {
            return;
        }

        CatalogTitleReviewAlias::query()
            ->where('canonical_review_id', $legacy->id)
            ->update([
                'canonical_review_id' => $canonical->id,
                'updated_at' => now(),
            ]);

        CatalogTitleReviewAlias::query()->updateOrCreate(
            ['legacy_review_id' => $legacy->id],
            [
                'canonical_review_id' => $canonical->id,
                'legacy_catalog_title_id' => $legacyTitle->id,
                'reason' => 'title_merge',
            ],
        );
    }

    private function removeVoteNotification(
        CatalogTitleReview $legacy,
        CatalogTitleReviewVote $vote,
    ): void {
        if (! $this->schema->notificationsAvailable()) {
            return;
        }

        $legacy->loadMissing('authorAccount:id');
        $recipient = $legacy->authorAccount;

        if (! $recipient instanceof User) {
            return;
        }

        $id = DeterministicUuid::from(
            'seasonvar.review.notification',
            $recipient->id.':helpful:'.$legacy->id.':'.$vote->user_id,
        );
        $recipient->notifications()->whereKey($id)->delete();
    }
}
