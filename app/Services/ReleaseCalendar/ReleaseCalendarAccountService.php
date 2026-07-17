<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Models\ReleaseCalendarNotificationPreference;
use App\Models\ReleaseCalendarSubscription;
use App\Models\User;

final readonly class ReleaseCalendarAccountService
{
    public function __construct(private ReleaseCalendarSchema $schema) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        if (! $this->schema->ready()) {
            return ['subscriptions' => [], 'notification_preferences' => null];
        }

        $preference = ReleaseCalendarNotificationPreference::query()->find($user->id);

        return [
            'subscriptions' => ReleaseCalendarSubscription::query()
                ->where('user_id', $user->id)
                ->with('catalogTitle:id,slug,title')
                ->orderBy('created_at')
                ->get()
                ->map(fn (ReleaseCalendarSubscription $subscription): array => [
                    'title_slug' => $subscription->catalogTitle?->slug,
                    'title' => $subscription->catalogTitle?->title,
                    'premiere_notifications' => $subscription->premiere_notifications,
                    'season_notifications' => $subscription->season_notifications,
                    'episode_notifications' => $subscription->episode_notifications,
                    'translation_notifications' => $subscription->translation_notifications,
                    'subtitle_notifications' => $subscription->subtitle_notifications,
                    'portal_publication_notifications' => $subscription->portal_publication_notifications,
                    'date_change_notifications' => $subscription->date_change_notifications,
                    'created_at' => $subscription->created_at?->toAtomString(),
                ])->all(),
            'notification_preferences' => $preference?->only([
                'premiere_notifications', 'season_notifications', 'episode_notifications',
                'translation_notifications', 'subtitle_notifications', 'date_change_notifications',
                'postponed_notifications', 'cancelled_notifications', 'portal_publication_notifications',
            ]),
        ];
    }
}
