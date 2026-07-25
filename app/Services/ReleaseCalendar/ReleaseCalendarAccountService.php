<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Models\ReleaseCalendarFeed;
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
            return ['subscriptions' => [], 'feeds' => [], 'notification_preferences' => null];
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
            'feeds' => $this->feeds($user),
            'notification_preferences' => $preference?->only([
                'premiere_notifications', 'season_notifications', 'episode_notifications',
                'translation_notifications', 'subtitle_notifications', 'date_change_notifications',
                'postponed_notifications', 'cancelled_notifications', 'portal_publication_notifications',
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function feeds(User $user): array
    {
        if (! $this->schema->feedsReady()) {
            return [];
        }

        return ReleaseCalendarFeed::query()
            ->whereBelongsTo($user)
            ->with([
                'catalogCollection:id,public_id,name',
                'catalogTitle:id,slug,title',
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->get()
            ->map(fn (ReleaseCalendarFeed $feed): array => [
                'public_id' => $feed->public_id,
                'scope' => $feed->scope->value,
                'collection' => $feed->catalogCollection === null ? null : [
                    'public_id' => $feed->catalogCollection->public_id,
                    'name' => $feed->catalogCollection->name,
                ],
                'title' => $feed->catalogTitle === null ? null : [
                    'slug' => $feed->catalogTitle->slug,
                    'title' => $feed->catalogTitle->title,
                ],
                'language_code' => $feed->language_code,
                'translation_name' => $feed->translation_name,
                'locale' => $feed->locale,
                'version' => $feed->version,
                'token_rotated_at' => $feed->token_rotated_at->toAtomString(),
                'created_at' => $feed->created_at?->toAtomString(),
                'updated_at' => $feed->updated_at?->toAtomString(),
            ])
            ->all();
    }
}
