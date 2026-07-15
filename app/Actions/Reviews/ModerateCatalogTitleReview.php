<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\AdminAuditAction;
use App\Enums\ReviewModerationReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Reviews\ReviewCacheInvalidator;
use App\Services\Reviews\ReviewModerationAudit;
use App\Services\Reviews\ReviewNotificationService;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ModerateCatalogTitleReview
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly ReviewCacheInvalidator $cache,
        private readonly ReviewNotificationService $notifications,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly ReviewModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $reviewId,
        ReviewStatus|string $status,
        ReviewModerationReason|string $reason,
        mixed $privateNote,
        ?bool $isSpoiler = null,
    ): CatalogTitleReview {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $status = is_string($status) ? ReviewStatus::tryFrom($status) : $status;
        $reason = is_string($reason) ? ReviewModerationReason::tryFrom($reason) : $reason;

        if (! $status instanceof ReviewStatus || ! $reason instanceof ReviewModerationReason) {
            throw new ReviewActionException('reviews.errors.invalid_moderation');
        }

        $review = CatalogTitleReview::query()->findOrFail($reviewId);
        Gate::forUser($moderator)->authorize('moderate', $review);
        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new ReviewActionException('reviews.errors.private_note_too_long', ['maximum' => 2_000]);
        }

        $this->rateLimiter->hit('moderate', $moderator, 'review:'.$review->id);

        /** @var array{review: CatalogTitleReview, changed: bool, was_public: bool, status_changed: bool, spoiler_changed: bool} $result */
        $result = DB::transaction(function () use (
            $review,
            $moderator,
            $status,
            $reason,
            $privateNote,
            $isSpoiler,
        ): array {
            $locked = CatalogTitleReview::query()->lockForUpdate()->findOrFail($review->id);
            Gate::forUser($moderator)->authorize('moderate', $locked);
            $before = $this->audit->review($locked);
            $wasPublic = $locked->status === ReviewStatus::Published && ! $locked->isDeleted();
            $statusChanged = $locked->status !== $status;
            $spoilerChanged = $isSpoiler !== null && $locked->is_spoiler !== $isSpoiler;
            $reasonChanged = $locked->moderation_reason !== $reason;
            $noteChanged = $locked->moderator_note !== $privateNote;

            if ($locked->status === $status
                && $locked->moderation_reason === $reason
                && $locked->moderator_note === $privateNote
                && ($isSpoiler === null || $locked->is_spoiler === $isSpoiler)) {
                return [
                    'review' => $locked,
                    'changed' => false,
                    'was_public' => $wasPublic,
                    'status_changed' => false,
                    'spoiler_changed' => false,
                ];
            }

            $updates = [
                'status' => $status,
                'moderated_by_id' => $moderator->id,
                'moderation_reason' => $reason,
                'moderator_note' => $privateNote,
                'moderated_at' => now(),
                'version' => (int) $locked->version + 1,
            ];

            if ($status === ReviewStatus::Published && $locked->published_at === null) {
                $updates['published_at'] = now();
            }

            if ($isSpoiler !== null) {
                $updates['is_spoiler'] = $isSpoiler;
            }

            $locked->forceFill($updates)->save();
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::ReviewModerated,
                $locked,
                $before,
                $this->audit->review($locked),
                [
                    ...($statusChanged ? ['review_status'] : []),
                    ...($spoilerChanged ? ['is_spoiler'] : []),
                    ...($reasonChanged ? ['moderation_reason'] : []),
                    ...($noteChanged ? ['moderator_note'] : []),
                ],
            );

            return [
                'review' => $locked,
                'changed' => true,
                'was_public' => $wasPublic,
                'status_changed' => $statusChanged,
                'spoiler_changed' => $spoilerChanged,
            ];
        }, attempts: 3);

        $review = $result['review'];

        if (! $result['changed']) {
            return $review;
        }

        $isPublic = $review->status === ReviewStatus::Published && ! $review->isDeleted();
        $visibilityChanged = $result['was_public'] !== $isPublic;

        if ($result['status_changed'] || $result['spoiler_changed']) {
            $this->cache->titleChanged(
                (int) $review->catalog_title_id,
                recommendations: $visibilityChanged,
                api: $visibilityChanged && $review->origin === ReviewOrigin::Provider,
            );
        }

        if ($result['status_changed']) {
            $this->notifications->moderationChanged($review, $moderator);
        }

        return $review;
    }
}
