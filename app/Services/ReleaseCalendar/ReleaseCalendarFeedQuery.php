<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseCalendarFeedScope;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Models\ReleaseCalendarFeed;
use App\Models\ReleaseScheduleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class ReleaseCalendarFeedQuery
{
    public function __construct(
        private ReleaseScheduleVisibility $visibility,
        private ReleaseCalendarQuery $calendar,
    ) {}

    /** @return Collection<int, ReleaseScheduleEntry> */
    public function entries(ReleaseCalendarFeed $feed): Collection
    {
        $user = $feed->user;
        $pastDays = max(0, (int) config('release-calendar.feeds.past_days', 60));
        $futureDays = max(1, (int) config('release-calendar.feeds.future_days', 400));
        $start = CarbonImmutable::now('UTC')->subDays($pastDays)->startOfDay();
        $end = CarbonImmutable::now('UTC')->addDays($futureDays)->endOfDay();
        $query = ReleaseScheduleEntry::query()
            ->select([
                'id',
                'public_id',
                'catalog_title_id',
                'entry_type',
                'status',
                'precision',
                'season_number',
                'episode_number',
                'language_code',
                'translation_name',
                'starts_at',
                'date_value',
                'date_end',
                'revision',
                'updated_at',
            ])
            ->with('catalogTitle:id,slug,title,original_title')
            ->whereIn('precision', [
                ReleaseDatePrecision::ExactDateTime->value,
                ReleaseDatePrecision::ExactDate->value,
                ReleaseDatePrecision::DateRange->value,
            ])
            ->where(function (Builder $window) use ($start, $end): void {
                $window->whereBetween('starts_at', [$start, $end])
                    ->orWhere(function (Builder $dates) use ($start, $end): void {
                        $dates->whereNotNull('date_value')
                            ->where('date_value', '<=', $end->toDateString())
                            ->where(function (Builder $overlap) use ($start): void {
                                $overlap->where('date_end', '>=', $start->toDateString())
                                    ->orWhere(function (Builder $exact) use ($start): void {
                                        $exact->whereNull('date_end')
                                            ->where('date_value', '>=', $start->toDateString());
                                    });
                            });
                    });
            });

        $this->visibility->constrain($query, $user);

        $hasExplicitTrackTitle = in_array($feed->scope, [
            ReleaseCalendarFeedScope::Translation,
            ReleaseCalendarFeedScope::Subtitles,
        ], true) && $feed->catalog_title_id !== null;

        if ($feed->scope->usesPersonalCalendar() && ! $hasExplicitTrackTitle) {
            $this->calendar->constrainPersonal($query, $user);
        }

        match ($feed->scope) {
            ReleaseCalendarFeedScope::All => null,
            ReleaseCalendarFeedScope::NewEpisodes => $query->where(
                'entry_type',
                ReleaseScheduleEntryType::EpisodeRelease->value,
            ),
            ReleaseCalendarFeedScope::SeasonPremieres => $query->where(
                'entry_type',
                ReleaseScheduleEntryType::SeasonPremiere->value,
            ),
            ReleaseCalendarFeedScope::Title => $query->where(
                'release_schedule_entries.catalog_title_id',
                $feed->catalog_title_id,
            ),
            ReleaseCalendarFeedScope::Translation => $query
                ->where('entry_type', ReleaseScheduleEntryType::TranslationRelease->value)
                ->where('translation_name', $feed->translation_name)
                ->when($feed->language_code !== null, fn (Builder $query): Builder => $query
                    ->where('language_code', $feed->language_code))
                ->when($feed->catalog_title_id !== null, fn (Builder $query): Builder => $query
                    ->where('release_schedule_entries.catalog_title_id', $feed->catalog_title_id)),
            ReleaseCalendarFeedScope::Subtitles => $query
                ->where('entry_type', ReleaseScheduleEntryType::SubtitleRelease->value)
                ->where('language_code', $feed->language_code)
                ->when($feed->catalog_title_id !== null, fn (Builder $query): Builder => $query
                    ->where('release_schedule_entries.catalog_title_id', $feed->catalog_title_id)),
            ReleaseCalendarFeedScope::Collection => $query->whereHas(
                'catalogTitle.collectionItems',
                fn (Builder $items): Builder => $items->where(
                    'catalog_collection_items.catalog_collection_id',
                    $feed->catalog_collection_id,
                ),
            ),
        };

        return $query
            ->orderByRaw('COALESCE(starts_at, date_value)')
            ->orderBy('release_schedule_entries.id')
            ->limit(max(1, (int) config('release-calendar.feeds.max_events', 1000)))
            ->get();
    }
}
