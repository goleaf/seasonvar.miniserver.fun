<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\DTOs\ReleaseCalendar\ReleaseScheduleCardData;
use App\DTOs\ReleaseCalendar\ReleaseScheduleGroupData;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final readonly class ReleaseCalendarQuery
{
    /** @var list<string> */
    private const GROUP_COLUMNS = [
        'catalog_title_id',
        'season_id',
        'entry_type',
        'status',
        'precision',
        'starts_at',
        'date_value',
        'date_end',
        'release_year',
        'release_month',
        'release_quarter',
        'is_estimated',
        'translation_name',
        'language_code',
        'season_number',
    ];

    private const INDIVIDUAL_GROUP_SQL = <<<'SQL'
        CASE
            WHEN release_schedule_entries.episode_id IS NOT NULL
                AND release_schedule_entries.episode_number IS NOT NULL
                AND release_schedule_entries.starts_at IS NOT NULL
            THEN 0
            ELSE release_schedule_entries.id
        END
        SQL;

    public function __construct(
        private ReleaseDatePresenter $dates,
        private ReleaseScheduleVisibility $visibility,
        private ReleaseCalendarEpisodeRangeFormatter $episodeRanges,
    ) {}

    /** @return LengthAwarePaginator<int, ReleaseScheduleGroupData> */
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
        $baseQuery = $this->filteredQuery($user, $view, $period, $type, $status, $timezone, $catalogTitleId);
        $groups = $this->groupQuery(clone $baseQuery, $sort)
            ->paginate(max(1, (int) config('release-calendar.per_page', 24)), pageName: 'calendarPage');
        $groupRows = $groups->getCollection();

        if ($groupRows->isEmpty()) {
            return new LengthAwarePaginator(
                [],
                $groups->total(),
                $groups->perPage(),
                $groups->currentPage(),
                $groups->getOptions(),
            );
        }

        $members = $this->membersForGroups(clone $baseQuery, $groupRows);
        $subscriptions = $user === null ? [] : ReleaseCalendarSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('catalog_title_id', $groupRows->pluck('catalog_title_id')->unique()->values())
            ->pluck('catalog_title_id')
            ->flip()
            ->all();
        $cardsByGroup = $members
            ->groupBy(fn (ReleaseScheduleEntry $entry): string => $this->groupKey($entry))
            ->map(fn (Collection $entries): Collection => $entries
                ->sortBy([
                    ['episode_number', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(fn (ReleaseScheduleEntry $entry): ReleaseScheduleCardData => $this->card(
                    $entry,
                    $locale,
                    $timezone,
                    isset($subscriptions[$entry->catalog_title_id]),
                    $user !== null,
                )));

        $pageGroups = $groupRows
            ->map(function (ReleaseScheduleEntry $groupRow) use ($cardsByGroup): ?ReleaseScheduleGroupData {
                /** @var Collection<int, ReleaseScheduleCardData> $cards */
                $cards = $cardsByGroup->get($this->groupKey($groupRow), collect());

                return $cards->isEmpty() ? null : $this->group($groupRow, $cards);
            })
            ->filter()
            ->values();

        return new LengthAwarePaginator(
            $pageGroups,
            $groups->total(),
            $groups->perPage(),
            $groups->currentPage(),
            $groups->getOptions(),
        );
    }

    /** @return Builder<ReleaseScheduleEntry> */
    private function filteredQuery(
        ?User $user,
        ReleaseCalendarView $view,
        ReleaseCalendarPeriod $period,
        ?ReleaseScheduleEntryType $type,
        ?ReleaseScheduleStatus $status,
        string $timezone,
        ?int $catalogTitleId,
    ): Builder {
        $query = ReleaseScheduleEntry::query();
        $this->visibility->constrain($query, $user);
        $this->constrainWindow($query, $view, $period, $timezone);

        if ($view === ReleaseCalendarView::Personal && $user !== null) {
            $this->constrainPersonal($query, $user);
        }

        $query
            ->when($type !== null, fn (Builder $query): Builder => $query->where('entry_type', $type->value))
            ->when(
                $status !== null,
                fn (Builder $query): Builder => $query->where('status', $status->value),
                fn (Builder $query): Builder => $view === ReleaseCalendarView::Recent
                    ? $query->where('status', ReleaseScheduleStatus::Released->value)
                    : $query,
            )
            ->when(
                $catalogTitleId !== null,
                fn (Builder $query): Builder => $query->where(
                    'release_schedule_entries.catalog_title_id',
                    $catalogTitleId,
                ),
            );

        return $query;
    }

    /**
     * @param  Builder<ReleaseScheduleEntry>  $query
     * @return Builder<ReleaseScheduleEntry>
     */
    private function groupQuery(Builder $query, ReleaseCalendarSort $sort): Builder
    {
        $query->select(array_map(
            static fn (string $column): string => 'release_schedule_entries.'.$column,
            self::GROUP_COLUMNS,
        ))
            ->selectRaw('MIN(release_schedule_entries.id) AS id')
            ->selectRaw(self::INDIVIDUAL_GROUP_SQL.' AS calendar_individual_id')
            ->groupBy(array_map(
                static fn (string $column): string => 'release_schedule_entries.'.$column,
                self::GROUP_COLUMNS,
            ))
            ->groupByRaw(self::INDIVIDUAL_GROUP_SQL);

        $this->sortGroups($query, $sort);

        return $query;
    }

    /**
     * @param  Builder<ReleaseScheduleEntry>  $query
     * @param  Collection<int, ReleaseScheduleEntry>  $groupRows
     * @return Collection<int, ReleaseScheduleEntry>
     */
    private function membersForGroups(Builder $query, Collection $groupRows): Collection
    {
        return $query
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
                'episode:id,season_id,number,title',
            ])
            ->where(function (Builder $members) use ($groupRows): void {
                foreach ($groupRows as $index => $groupRow) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $members->{$method}(function (Builder $member) use ($groupRow): void {
                        $individualId = (int) $groupRow->getAttribute('calendar_individual_id');

                        if ($individualId > 0) {
                            $member->where('release_schedule_entries.id', $individualId);

                            return;
                        }

                        foreach (self::GROUP_COLUMNS as $column) {
                            $value = $groupRow->getRawOriginal($column);

                            if ($value === null) {
                                $member->whereNull('release_schedule_entries.'.$column);
                            } else {
                                $member->where('release_schedule_entries.'.$column, $value);
                            }
                        }

                        $member
                            ->whereNotNull('release_schedule_entries.episode_id')
                            ->whereNotNull('release_schedule_entries.episode_number')
                            ->whereNotNull('release_schedule_entries.starts_at');
                    });
                }
            })
            ->orderBy('release_schedule_entries.id')
            ->get();
    }

    /** @param Builder<ReleaseScheduleEntry> $query */
    private function sortGroups(Builder $query, ReleaseCalendarSort $sort): void
    {
        if ($sort === ReleaseCalendarSort::Title) {
            $query
                ->join('catalog_titles as release_titles', 'release_titles.id', '=', 'release_schedule_entries.catalog_title_id')
                ->addSelect('release_titles.title AS calendar_sort_title')
                ->groupBy('release_titles.title')
                ->orderBy('release_titles.title')
                ->orderByRaw('MIN(release_schedule_entries.id)');

            return;
        }

        $direction = $sort === ReleaseCalendarSort::Latest ? 'desc' : 'asc';
        $query
            ->orderByRaw('CASE WHEN starts_at IS NULL AND date_value IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(starts_at, date_value) '.$direction)
            ->orderBy('release_year', $direction)
            ->orderBy('release_month', $direction)
            ->orderByRaw('MIN(release_schedule_entries.id) '.$direction);
    }

    private function groupKey(ReleaseScheduleEntry $entry): string
    {
        $individualId = $entry->getAttributes()['calendar_individual_id'] ?? null;

        if ($individualId === null) {
            $individualId = $entry->episode_id !== null
                && $entry->episode_number !== null
                && $entry->starts_at !== null
                    ? 0
                    : $entry->id;
        }

        $values = [(int) $individualId];

        foreach (self::GROUP_COLUMNS as $column) {
            $values[] = $entry->getRawOriginal($column);
        }

        return hash('sha256', serialize($values));
    }

    /**
     * @param  Collection<int, ReleaseScheduleCardData>  $cards
     */
    private function group(ReleaseScheduleEntry $groupRow, Collection $cards): ReleaseScheduleGroupData
    {
        $cards = $cards
            ->unique(static fn (ReleaseScheduleCardData $card): ?int => $card->episodeNumber)
            ->values();
        /** @var ReleaseScheduleCardData $primary */
        $primary = $cards->first();
        $isBatch = $cards->count() > 1;
        $range = $this->episodeRanges->format(
            $cards
                ->map(fn (ReleaseScheduleCardData $card): ?int => $card->episodeNumber)
                ->filter(static fn (?int $number): bool => $number !== null),
        );

        return new ReleaseScheduleGroupData(
            key: $this->groupKey($groupRow),
            primary: $primary,
            entries: $cards->all(),
            isBatch: $isBatch,
            batchLabel: $isBatch ? __('calendar.batches.labels.'.$primary->type, ['episodes' => $range]) : null,
            detailLabel: $isBatch ? __('calendar.batches.details', ['count' => $cards->count()]) : null,
        );
    }

    public function hasUpcoming(ReleaseCalendarPeriod $period, string $timezone): bool
    {
        $query = ReleaseScheduleEntry::query();
        $this->visibility->constrain($query, null);
        $this->constrainWindow($query, ReleaseCalendarView::Upcoming, $period, $timezone);

        return $query->exists();
    }

    public function hasRecent(ReleaseCalendarPeriod $period, string $timezone): bool
    {
        $query = ReleaseScheduleEntry::query();
        $this->visibility->constrain($query, null);
        $this->constrainWindow($query, ReleaseCalendarView::Recent, $period, $timezone);

        return $query->where('status', ReleaseScheduleStatus::Released->value)->exists();
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
    public function constrainPersonal(Builder $query, User $user): void
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
                                CatalogWatchStatus::Paused->value,
                                CatalogWatchStatus::Completed->value,
                            ]);
                    })
                    ->where(function (Builder $state): void {
                        $state->whereNull('recommendation_feedback')
                            ->orWhereNotIn(
                                'recommendation_feedback',
                                CatalogRecommendationFeedback::negativeValues(),
                            );
                    })
                    ->selectRaw('1')
                    ->toBase(),
            );
        });
        $query->whereNotExists(
            CatalogTitleUserState::query()
                ->whereColumn('catalog_title_user_states.catalog_title_id', 'release_schedule_entries.catalog_title_id')
                ->where('catalog_title_user_states.user_id', $user->id)
                ->whereIn(
                    'catalog_title_user_states.recommendation_feedback',
                    CatalogRecommendationFeedback::negativeValues(),
                )
                ->selectRaw('1')
                ->toBase(),
        );
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
            episodeNumber: $entry->episode_number,
            episodeLabel: $episodeLabel,
            episodeTitle: $entry->episode?->title,
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
