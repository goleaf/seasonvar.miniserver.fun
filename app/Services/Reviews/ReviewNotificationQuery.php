<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\DTOs\Reviews\ReviewNotificationData;
use App\Enums\ReviewNotificationType;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

final class ReviewNotificationQuery
{
    public function __construct(
        private readonly AccountSettingsService $settings,
        private readonly AccountDateTimeFormatter $dateTimes,
    ) {}

    /** @return LengthAwarePaginator<int, ReviewNotificationData> */
    public function forUser(User $user): LengthAwarePaginator
    {
        $paginator = $user->notifications()
            ->where('type', 'review.activity')
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, pageName: 'reviewNotificationPage')
            ->withQueryString();
        $reviewIds = $paginator->getCollection()
            ->map(fn (DatabaseNotification $notification): mixed => $notification->data['review_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();
        $reviews = CatalogTitleReview::query()
            ->whereKey($reviewIds)
            ->get(['id', 'user_id', 'status', 'deleted_at', 'merged_into_id'])
            ->keyBy('id');
        $accountSettings = $this->settings->resolve($user);

        return $paginator->through(function (DatabaseNotification $notification) use ($reviews, $accountSettings): ReviewNotificationData {
            $data = $notification->data;
            $kind = is_string($data['kind'] ?? null)
                ? ReviewNotificationType::tryFrom($data['kind'])
                : null;
            $reviewId = is_numeric($data['review_id'] ?? null) ? (int) $data['review_id'] : null;
            $review = $reviewId !== null ? $reviews->get($reviewId) : null;
            $moderationStatus = is_string($data['moderation_status'] ?? null)
                ? ReviewStatus::tryFrom($data['moderation_status'])
                : null;
            $url = null;

            if ($review instanceof CatalogTitleReview) {
                if ($kind === ReviewNotificationType::Moderation) {
                    $url = route('profile.reviews');
                } elseif ($review->merged_into_id !== null || (
                    $review->status === ReviewStatus::Published
                    && $review->deleted_at === null
                )) {
                    $url = route('reviews.show', ['review' => $review->id]);
                }
            }

            return new ReviewNotificationData(
                id: (string) $notification->id,
                isRead: $notification->read_at !== null,
                label: $kind !== null
                    ? __('reviews.notifications.'.$kind->value)
                    : __('reviews.notifications.activity'),
                detail: $kind === ReviewNotificationType::Moderation && $moderationStatus !== null
                    ? __('reviews.notifications.moderation_status', ['status' => $moderationStatus->label()])
                    : null,
                url: $url,
                createdAtIso: $notification->created_at?->toAtomString() ?? '',
                createdAtLabel: $notification->created_at !== null
                    ? $this->dateTimes->value($notification->created_at, $accountSettings->locale, $accountSettings->timezone)
                    : '',
            );
        });
    }
}
