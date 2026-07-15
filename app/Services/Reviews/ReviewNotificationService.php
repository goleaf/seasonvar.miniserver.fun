<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\ReviewNotificationType;
use App\Enums\ReviewVoteType;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewAlias;
use App\Models\CatalogTitleReviewNotificationPreference;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewVote;
use App\Models\User;
use App\Notifications\ReviewActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReviewNotificationService
{
    public function __construct(
        private readonly ReviewRelationshipService $relationships,
        private readonly ReviewSchema $schema,
    ) {}

    public function voteChanged(
        ?CatalogTitleReviewVote $vote,
        CatalogTitleReview $review,
        User $actor,
    ): void {
        $this->safely(fn () => $this->deliverVoteChanged($vote, $review, $actor));
    }

    public function moderationChanged(CatalogTitleReview $review, ?User $actor = null): void
    {
        $this->safely(fn () => $this->deliverModerationChanged($review, $actor));
    }

    public function reportResolved(CatalogTitleReviewReport $report, ?User $actor = null): void
    {
        $this->safely(fn () => $this->deliverReportResolved($report, $actor));
    }

    private function deliverVoteChanged(
        ?CatalogTitleReviewVote $vote,
        CatalogTitleReview $review,
        User $actor,
    ): void {
        if (! $this->schema->notificationsAvailable()) {
            return;
        }

        $review->loadMissing('authorAccount:id,name');
        $recipient = $review->authorAccount;

        if (! $recipient instanceof User) {
            return;
        }

        $deduplicationKey = 'helpful:'.$review->id.':'.$actor->id;
        $legacyReviewIds = CatalogTitleReviewAlias::query()
            ->where('canonical_review_id', $review->id)
            ->pluck('legacy_review_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $legacyNotificationIds = collect($legacyReviewIds)
            ->map(fn (int $reviewId): string => $this->id(
                $recipient,
                'helpful:'.$reviewId.':'.$actor->id,
            ))
            ->all();

        if ($vote === null || $vote->type !== ReviewVoteType::Helpful) {
            $recipient->notifications()->whereKey([
                $this->id($recipient, $deduplicationKey),
                ...$legacyNotificationIds,
            ])->delete();

            return;
        }

        if ($legacyNotificationIds !== []) {
            $recipient->notifications()->whereKey($legacyNotificationIds)->delete();
        }

        if (! $this->relationships->shouldNotify($recipient, $actor)
            || ! $this->preference($recipient)->helpful_notifications) {
            return;
        }

        $this->deliver(
            $recipient,
            $deduplicationKey,
            new ReviewActivityNotification(
                ReviewNotificationType::Helpful,
                reviewId: (int) $review->id,
                voteId: (int) $vote->id,
            ),
        );
    }

    private function deliverModerationChanged(CatalogTitleReview $review, ?User $actor): void
    {
        if (! $this->schema->notificationsAvailable()) {
            return;
        }

        $review->loadMissing('authorAccount:id,name');
        $recipient = $review->authorAccount;

        if (! $recipient instanceof User
            || ($actor !== null && $recipient->is($actor))
            || ! $this->preference($recipient)->moderation_notifications) {
            return;
        }

        $this->deliver(
            $recipient,
            'moderation:'.$review->id.':'.$review->status->value.':'.$review->version,
            new ReviewActivityNotification(
                ReviewNotificationType::Moderation,
                reviewId: (int) $review->id,
                moderationStatus: $review->status->value,
            ),
        );
    }

    private function deliverReportResolved(CatalogTitleReviewReport $report, ?User $actor): void
    {
        if (! $this->schema->notificationsAvailable()) {
            return;
        }

        $report->loadMissing('reporter:id,name');
        $recipient = $report->reporter;

        if (! $recipient instanceof User
            || ($actor !== null && $recipient->is($actor))
            || ! $this->preference($recipient)->report_notifications) {
            return;
        }

        $this->deliver(
            $recipient,
            'report:'.$report->id.':'.$report->status->value,
            new ReviewActivityNotification(
                ReviewNotificationType::ReportResolved,
                reviewId: (int) $report->catalog_title_review_id,
                reportId: (int) $report->id,
            ),
        );
    }

    private function preference(User $user): CatalogTitleReviewNotificationPreference
    {
        return CatalogTitleReviewNotificationPreference::query()->firstOrCreate(['user_id' => $user->id]);
    }

    private function deliver(User $recipient, string $key, ReviewActivityNotification $notification): void
    {
        $notification->id = $this->id($recipient, $key);

        DB::transaction(function () use ($recipient, $notification): void {
            $lockedRecipient = User::query()->lockForUpdate()->find($recipient->id);

            if (! $lockedRecipient instanceof User
                || $lockedRecipient->notifications()->whereKey($notification->id)->exists()) {
                return;
            }

            try {
                $lockedRecipient->notify($notification);
            } catch (QueryException $exception) {
                if (! $lockedRecipient->notifications()->whereKey($notification->id)->exists()) {
                    throw $exception;
                }
            }
        }, attempts: 3);
    }

    private function id(User $recipient, string $key): string
    {
        return DeterministicUuid::from('seasonvar.review.notification', $recipient->id.':'.$key);
    }

    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
