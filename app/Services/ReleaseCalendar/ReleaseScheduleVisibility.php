<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseScheduleEntryType;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ReleaseScheduleVisibility
{
    /**
     * @param  Builder<ReleaseScheduleEntry>  $query
     * @return Builder<ReleaseScheduleEntry>
     */
    public function constrain(Builder $query, ?User $user): Builder
    {
        return $query
            ->where('release_schedule_entries.is_public', true)
            ->whereHas('catalogTitle', fn (Builder $title): Builder => $title->availableTo($user))
            ->where(fn (Builder $entry): Builder => $entry
                ->whereNull('release_schedule_entries.season_id')
                ->orWhereHas('season', fn (Builder $season): Builder => $season->availableTo($user)))
            ->where(fn (Builder $entry): Builder => $entry
                ->whereNull('release_schedule_entries.episode_id')
                ->orWhereHas('episode', fn (Builder $episode): Builder => $episode->availableTo($user)))
            ->where(function (Builder $entry) use ($user): void {
                $entry
                    ->whereNotIn('release_schedule_entries.entry_type', [
                        ReleaseScheduleEntryType::PortalPublication->value,
                        ReleaseScheduleEntryType::TranslationRelease->value,
                        ReleaseScheduleEntryType::SubtitleRelease->value,
                        ReleaseScheduleEntryType::QualityUpgrade->value,
                    ])
                    ->orWhere(function (Builder $event) use ($user): void {
                        $event->where('release_schedule_entries.entry_type', ReleaseScheduleEntryType::PortalPublication->value)
                            ->whereHas('episode.licensedMedia', fn (Builder $media): Builder => $this->availableMedia($media, $user));
                    })
                    ->orWhere(function (Builder $event) use ($user): void {
                        $event->where('release_schedule_entries.entry_type', ReleaseScheduleEntryType::TranslationRelease->value)
                            ->whereHas('episode.licensedMedia', fn (Builder $media): Builder => $this->availableMedia($media, $user)
                                ->whereColumn('licensed_media.translation_name', 'release_schedule_entries.translation_name'));
                    })
                    ->orWhere(function (Builder $event) use ($user): void {
                        $event->where('release_schedule_entries.entry_type', ReleaseScheduleEntryType::SubtitleRelease->value)
                            ->whereHas('episode.licensedMedia', fn (Builder $media): Builder => $this->availableMedia($media, $user)
                                ->where('licensed_media.has_subtitles', true));
                    })
                    ->orWhere(function (Builder $event) use ($user): void {
                        $event->where('release_schedule_entries.entry_type', ReleaseScheduleEntryType::QualityUpgrade->value)
                            ->whereHas('licensedMedia', fn (Builder $media): Builder => $this->availableMedia($media, $user));
                    });
            });
    }

    /** @param Builder<LicensedMedia> $query */
    private function availableMedia(Builder $query, ?User $user): Builder
    {
        return $query
            ->availableTo($user)
            ->forAvailableReleases($user)
            ->withPlaybackLocation()
            ->withoutKnownFailures();
    }
}
