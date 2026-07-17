<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\DTOs\ReleaseCalendar\ReleaseScheduleCardData;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Enums\ReleaseCalendarSort;
use App\Enums\ReleaseCalendarView;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitleUserState;
use App\Models\ReleaseCalendarSubscription;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReleaseCalendarQuery
{
    public function __construct(
        private ReleaseDatePresenter $dates,
        private ReleaseScheduleVisibility $visibility,
    ) {}

    /** @return LengthAwarePaginator<int, ReleaseScheduleCardData> */
    public function entries(
        ?User $user,
        ReleaseCalendarView $view,
        ReleaseCalendarPeriod $period,
        ?ReleaseScheduleEntryType $type,
        ?ReleaseScheduleStatus $status,
        ReleaseCalendarSort $sort,
        string $locale,
        string $timezone,
        ?int $catalogTitleId = null,
    ): LengthAwarePaginator {
        $query = ReleaseScheduleEntry::query()
            ->select(array_map(
                static fn (string $column): string => 'release_schedule_entries.'.$column,
                [
                    'id', 'public_id', 'catalog_title_id', 'season_id', 'episode_id', 'licensed_media_id',
                    'entry_type', 'status', 'precision', 'starts_at', 'date_value', 'date_end',
                    'release_year', 'release_month', 'release_quarter', 'is_estimated', 'translation_name',
                    'language_code', 'season_number', 'episode_number',
                ],
            ))
            ->with([
                'catalogTitle:id,slug,title,original_title,poster_url',
                'season:id,catalog_title_id,number,kind,title',
                'episode:id,season_id,number,kind,title',
                'licensedMedia:id,catalog_title_id,season_id,episode_id,status,published_at,translation_name,has_subtitles',
            ]);
        $this->visibility->constrain($query, $user);

        $this->constrainWindow($query, $view, $period, $timezone);

        if ($view === ReleaseCalendarView::Personal && $user !== null) {
            $this->constrainPersonal($query, $user);
        }

        if ($type !== null) {
            $query->where('entry_type', $type->value);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($view === ReleaseCalendarView::Recent) {
            $query->where('status', ReleaseScheduleStatus::Released->value);
        }

        if ($catalogTitleId !== null) {
            $query->where('release_schedule_entries.catalog_title_id', $catalogTitleId);
        }

        $this->sort($query, $sort);

        $entries = $query->paginate(max(1, (int) config('release-calendar.per_page', 24)), pageName: 'calendarPage');
        $subscriptions = $user === null ? [] : ReleaseCalendarSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('catalog_title_id', $entries->getCollection()->pluck('catalog_title_id')->unique()->values())
            ->pluck('catalog_title_id')
            ->flip()
            ->all();

        return $entries
            ->through(fn (ReleaseScheduleEntry $entry): ReleaseScheduleCardData => $this->card(
                $entry,
                $locale,
                $timezone,
                isset($subscriptions[$entry->catalog_title_id]),
                $user !== null,
            ));
    }

    public function hasUpcoming(ReleaseCalendarPeriod $period, string $timezone): bool
    {
        $query = ReleaseScheduleEntry::query();
        $this->visibility->constrain($query, null);
        $this->constrainWindow($query, ReleaseCalendarView::Upcoming, $period, $timezone);

        return $query->exists();
    }

    /** @param Builder<ReleaseScheduleEntry> $query */
    private function constrainWindow(Builder $query, ReleaseCalendarView $view, ReleaseCalendarPeriod $period, string $timezone): void
    {
        $utcStart = $period->start->utc();
        $utcEnd = $period->end->utc();
        $dateStart = $period->start->toDateString();
        $dateEnd = $period->end->toDateString();

        $query->where(function (Builder $query) use ($utcStart, $utcEnd, $dateStart, $dateEnd, $view, $period): void {
            $query->whereBetween('starts_at', [$utcStart, $utcEnd])
                ->orWhere(function (Builder $dates) use ($dateStart, $dateEnd): void {
                    $dates->whereNotNull('date_value')
                        ->where('date_value', '<=', $dateEnd)
                        ->where(function (Builder $overlap) use ($dateStart): void {
                            $overlap->where(function (Builder $exact) use ($dateStart): void {
                                $exact->whereNull('date_end')->where('date_value', '>=', $dateStart);
                            })->orWhere('date_end', '>=', $dateStart);
                        });
                });

            if (in_array($view, [ReleaseCalendarView::Upcoming, ReleaseCalendarView::Personal], true)) {
                $query->orWhere(function (Builder $query): void {
                    $query->whereNull('starts_at')->whereNull('date_value')->whereNull('release_year');
                });
                $query->orWhereBetween('release_year', [$period->start->year, $period->end->year]);
            }

            if ($view === ReleaseCalendarView::Month) {
                $query->orWhere(function (Builder $query) use ($period): void {
                    $query->where('release_year', $period->start->year)
                        ->where(function (Builder $query) use ($period): void {
                            $query->where('release_month', $period->start->month)
                                ->orWhere('release_quarter', (int) ceil($period->start->month / 3))
                                ->orWhereNull('release_month');
                        });
                });
            }
        });
    }

    /** @return array<string, int> */
    public function monthCounts(
        ?User $user,
        ReleaseCalendarPeriod $period,
        string $timezone,
        ?ReleaseScheduleEntryType $type = null,
        ?ReleaseScheduleStatus $status = null,
        ?int $catalogTitleId = null,
    ): array {
        $counts = [];
        $query = ReleaseScheduleEntry::query()
            ->select(['id', 'starts_at', 'date_value'])
            ->where(function (Builder $query) use ($period): void {
                $query->whereBetween('starts_at', [$period->start->utc(), $period->end->utc()])
                    ->orWhereBetween('date_value', [$period->start->toDateString(), $period->end->toDateString()]);
            })
            ->when($type !== null, fn (Builder $query): Builder => $query->where('entry_type', $type->value))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value))
            ->when($catalogTitleId !== null, fn (Builder $query): Builder => $query->where('release_schedule_entries.catalog_title_id', $catalogTitleId));
        $this->visibility->constrain($query, $user);
        $query
            ->orderBy('id')
            ->chunkById(1_000, function ($entries) use (&$counts, $timezone): void {
                foreach ($entries as $entry) {
                    $date = $entry->starts_at?->setTimezone($timezone)->toDateString() ?? $entry->date_value?->toDateString();

                    if (is_string($date)) {
                        $counts[$date] = ($counts[$date] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }

    /** @param Builder<ReleaseScheduleEntry> $query */
    private function constrainPersonal(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereExists(
                ReleaseCalendarSubscription::query()
                    ->whereColumn('release_calendar_subscriptions.catalog_title_id', 'release_schedule_entries.catalog_title_id')
                    ->where('release_calendar_subscriptions.user_id', $user->id)
                    ->selectRaw('1')
                    ->toBase(),
            )->orWhereExists(
                CatalogTitleUserState::query()
                    ->whereColumn('catalog_title_user_states.catalog_title_id', 'release_schedule_entries.catalog_title_id')
                    ->where('catalog_title_user_states.user_id', $user->id)
                    ->where(function (Builder $state): void {
                        $state->where('in_watchlist', true)
                            ->orWhereIn('watch_status', [
                                CatalogWatchStatus::Planned->value,
                                CatalogWatchStatus::Watching->value,
                                CatalogWatchStatus::Completed->value,
                            ]);
                    })
                    ->where(function (Builder $state): void {
                        $state->whereNull('recommendation_feedback')
                            ->orWhereNotIn('recommendation_feedback', [
                                CatalogRecommendationFeedback::NotInterested->value,
                                CatalogRecommendationFeedback::Blacklisted->value,
                            ]);
                    })
                    ->selectRaw('1')
                    ->toBase(),
            );
        });
        $query->whereNotExists(
            CatalogTitleUserState::query()
                ->whereColumn('catalog_title_user_states.catalog_title_id', 'release_schedule_entries.catalog_title_id')
                ->where('catalog_title_user_states.user_id', $user->id)
                ->whereIn('catalog_title_user_states.recommendation_feedback', [
                    CatalogRecommendationFeedback::NotInterested->value,
                    CatalogRecommendationFeedback::Blacklisted->value,
                ])
                ->selectRaw('1')
                ->toBase(),
        );
    }

    /** @param Builder<ReleaseScheduleEntry> $query */
    private function sort(Builder $query, ReleaseCalendarSort $sort): void
    {
        if ($sort === ReleaseCalendarSort::Title) {
            $query->join('catalog_titles as release_titles', 'release_titles.id', '=', 'release_schedule_entries.catalog_title_id')
                ->orderBy('release_titles.title')
                ->orderBy('release_schedule_entries.id');

            return;
        }

        $direction = $sort === ReleaseCalendarSort::Latest ? 'desc' : 'asc';
        $query->orderByRaw('CASE WHEN starts_at IS NULL AND date_value IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(starts_at, date_value) '.$direction)
            ->orderBy('release_year', $direction)
            ->orderBy('release_month', $direction)
            ->orderBy('id', $direction);
    }

    private function card(ReleaseScheduleEntry $entry, string $locale, string $timezone, bool $subscribed, bool $canSubscribe): ReleaseScheduleCardData
    {
        $now = CarbonImmutable::now($timezone);
        $localDate = $this->dates->localDate($entry, $timezone);
        $expired = in_array($entry->status, [
            ReleaseScheduleStatus::Scheduled,
            ReleaseScheduleStatus::Estimated,
            ReleaseScheduleStatus::Confirmed,
        ], true) && match ($entry->precision) {
            ReleaseDatePrecision::ExactDateTime => $localDate?->lessThan($now) === true,
            ReleaseDatePrecision::ExactDate => $localDate?->endOfDay()->lessThan($now) === true,
            ReleaseDatePrecision::DateRange => $entry->date_end?->endOfDay()->lessThan($now) === true,
            default => false,
        };
        $status = $expired ? ReleaseScheduleStatus::Delayed : $entry->status;
        $seasonLabel = $entry->season_number !== null ? __('calendar.season_number', ['number' => $entry->season_number]) : null;
        $episodeLabel = $entry->episode_number !== null ? __('calendar.episode_number', ['number' => $entry->episode_number]) : null;
        $availability = filled($entry->translation_name)
            ? __('calendar.translation_name', ['name' => $entry->translation_name])
            : (filled($entry->language_code) ? __('calendar.language_code', ['code' => $entry->language_code]) : null);
        $contextLabel = implode(' · ', array_filter([$seasonLabel, $episodeLabel], is_string(...)));

        return new ReleaseScheduleCardData(
            publicId: $entry->public_id,
            catalogTitleId: $entry->catalog_title_id,
            title: $entry->catalogTitle->display_title,
            originalTitle: $entry->catalogTitle->display_original_title,
            posterUrl: $entry->catalogTitle->poster_url,
            type: $entry->entry_type->value,
            typeLabel: $entry->entry_type->label(),
            status: $status->value,
            statusLabel: $status->label(),
            precisionLabel: $entry->precision->label(),
            dateLabel: $this->dates->label($entry, $locale, $timezone),
            groupLabel: $this->dates->groupLabel($entry, $locale, $timezone),
            dateTimeIso: $entry->starts_at?->toIso8601String() ?? $entry->date_value?->toDateString(),
            countdownIso: ! $expired && $entry->starts_at?->isFuture() === true && ! $status->isTerminal()
                ? $entry->starts_at->toIso8601String()
                : null,
            seasonLabel: $seasonLabel,
            episodeLabel: $episodeLabel,
            contextLabel: $contextLabel !== '' ? $contextLabel : null,
            availabilityLabel: $availability,
            url: route('titles.show', ['catalogTitle' => $entry->catalogTitle->slug]),
            isSubscribed: $subscribed,
            canSubscribe: $canSubscribe,
            isCancelled: $status === ReleaseScheduleStatus::Cancelled,
            isDelayed: $status === ReleaseScheduleStatus::Delayed,
        );
    }
}
