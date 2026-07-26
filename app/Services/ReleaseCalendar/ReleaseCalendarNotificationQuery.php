<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\DTOs\ReleaseCalendar\ReleaseCalendarNotificationData;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\ReleaseCalendarNotificationType;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

final readonly class ReleaseCalendarNotificationQuery
{
    public function __construct(
        private AccountSettingsService $settings,
        private AccountDateTimeFormatter $dateTimes,
        private ReleaseScheduleVisibility $visibility,
    ) {}

    /** @return LengthAwarePaginator<int, ReleaseCalendarNotificationData> */
    public function forUser(User $user): LengthAwarePaginator
    {
        $visibleEntries = ReleaseScheduleEntry::query()
            ->selectRaw('1')
            ->whereColumn('release_schedule_entries.public_id', 'notifications.data->entry_public_id')
            ->whereDoesntHave('catalogTitle.userStates', fn ($state) => $state
                ->where('user_id', $user->id)
                ->whereIn(
                    'recommendation_feedback',
                    CatalogRecommendationFeedback::negativeValues(),
                ));
        $this->visibility->constrain($visibleEntries, $user);
        $visibleFavoriteTitles = CatalogTitle::query()
            ->availableTo($user)
            ->selectRaw('1')
            ->whereColumn('catalog_titles.slug', 'notifications.data->catalog_title_slug')
            ->whereDoesntHave('userStates', fn ($state) => $state
                ->where('user_id', $user->id)
                ->whereIn(
                    'recommendation_feedback',
                    CatalogRecommendationFeedback::negativeValues(),
                ));

        $paginator = $user->notifications()
            ->where(function ($query) use ($visibleEntries, $visibleFavoriteTitles): void {
                $query
                    ->where(function ($query) use ($visibleEntries): void {
                        $query
                            ->where('type', 'release-calendar.activity')
                            ->whereExists($visibleEntries->toBase());
                    })
                    ->orWhere(function ($query) use ($visibleFavoriteTitles): void {
                        $query
                            ->where('type', 'playback-preference.translation-available')
                            ->whereExists($visibleFavoriteTitles->toBase());
                    });
            })
            ->latest('created_at')->latest('id')->paginate(10, pageName: 'releaseNotificationPage')->withQueryString();
        $entryIds = $paginator->getCollection()->pluck('data.entry_public_id')
            ->filter(fn (mixed $publicId): bool => is_string($publicId))
            ->unique()
            ->values();
        $entriesQuery = ReleaseScheduleEntry::query()->whereIn('public_id', $entryIds)
            ->whereDoesntHave('catalogTitle.userStates', fn ($state) => $state
                ->where('user_id', $user->id)
                ->whereIn(
                    'recommendation_feedback',
                    CatalogRecommendationFeedback::negativeValues(),
                ));
        $this->visibility->constrain($entriesQuery, $user);
        $entries = $entriesQuery
            ->with('catalogTitle:id,slug,title,original_title')
            ->get(['id', 'public_id', 'catalog_title_id', 'entry_type', 'status'])
            ->keyBy('public_id');
        $favoriteTitleSlugs = $paginator->getCollection()->pluck('data.catalog_title_slug')
            ->filter(fn (mixed $slug): bool => is_string($slug))
            ->unique()
            ->values();
        $favoriteTitles = CatalogTitle::query()
            ->availableTo($user)
            ->whereIn('slug', $favoriteTitleSlugs)
            ->whereDoesntHave('userStates', fn ($state) => $state
                ->where('user_id', $user->id)
                ->whereIn(
                    'recommendation_feedback',
                    CatalogRecommendationFeedback::negativeValues(),
                ))
            ->get(['id', 'slug', 'title', 'original_title'])
            ->keyBy('slug');
        $settings = $this->settings->resolve($user);

        return $paginator->through(function (DatabaseNotification $notification) use ($entries, $favoriteTitles, $settings): ReleaseCalendarNotificationData {
            $data = $notification->data;

            if ($notification->type === 'playback-preference.translation-available') {
                $title = is_string($data['catalog_title_slug'] ?? null)
                    ? $favoriteTitles->get($data['catalog_title_slug'])
                    : null;
                $variantLabel = is_string($data['variant_label'] ?? null)
                    ? $data['variant_label']
                    : __('catalog.player.voiceover');

                return new ReleaseCalendarNotificationData(
                    id: (string) $notification->id,
                    isRead: $notification->read_at !== null,
                    label: __('playback.notifications.favorite_translation_available'),
                    detail: $title instanceof CatalogTitle
                        ? __('playback.notifications.favorite_translation_detail', [
                            'title' => $title->display_title,
                            'variant' => $variantLabel,
                        ])
                        : null,
                    url: $title instanceof CatalogTitle ? route('titles.show', $title) : route('calendar.index'),
                    createdAtIso: $notification->created_at?->toAtomString() ?? '',
                    createdAtLabel: $notification->created_at !== null
                        ? $this->dateTimes->value($notification->created_at, $settings->locale, $settings->timezone)
                        : '',
                );
            }

            $kind = is_string($data['kind'] ?? null) ? ReleaseCalendarNotificationType::tryFrom($data['kind']) : null;
            $entryType = is_string($data['entry_type'] ?? null) ? ReleaseScheduleEntryType::tryFrom($data['entry_type']) : null;
            $status = is_string($data['status'] ?? null) ? ReleaseScheduleStatus::tryFrom($data['status']) : null;
            $entry = is_string($data['entry_public_id'] ?? null) ? $entries->get($data['entry_public_id']) : null;
            $detail = $entry instanceof ReleaseScheduleEntry && $entry->catalogTitle instanceof CatalogTitle
                ? __('calendar.notifications.detail', [
                    'title' => $entry->catalogTitle->display_title,
                    'type' => $entryType?->label() ?? __('calendar.schedule'),
                    'status' => $status?->label() ?? __('calendar.statuses.unknown'),
                ])
                : null;
            $previousDate = is_string($data['previous_date'] ?? null) ? $data['previous_date'] : null;
            $newDate = is_string($data['new_date'] ?? null) ? $data['new_date'] : null;

            if ($kind === ReleaseCalendarNotificationType::DateChanged && $previousDate !== null && $newDate !== null) {
                $detail = __('calendar.notifications.date_changed_detail', [
                    'title' => $entry?->catalogTitle?->display_title ?? __('calendar.title'),
                    'from' => $this->notificationDate($previousDate, $settings->locale, $settings->timezone),
                    'to' => $this->notificationDate($newDate, $settings->locale, $settings->timezone),
                ]);
            }

            return new ReleaseCalendarNotificationData(
                id: (string) $notification->id,
                isRead: $notification->read_at !== null,
                label: $kind?->label() ?? __('calendar.notifications.title'),
                detail: $detail,
                url: route('calendar.index'),
                createdAtIso: $notification->created_at?->toAtomString() ?? '',
                createdAtLabel: $notification->created_at !== null
                    ? $this->dateTimes->value($notification->created_at, $settings->locale, $settings->timezone)
                    : '',
            );
        });
    }

    private function notificationDate(string $value, string $locale, string $timezone): string
    {
        $date = CarbonImmutable::parse($value, str_contains($value, 'T') ? 'UTC' : $timezone);

        return str_contains($value, 'T')
            ? $this->dateTimes->value($date, $locale, $timezone)
            : $this->dateTimes->date($date, $locale, $timezone);
    }
}
