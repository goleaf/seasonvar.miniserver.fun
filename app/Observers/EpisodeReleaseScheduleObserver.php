<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\Episode;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationService;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleIdentity;
use Illuminate\Support\Facades\DB;

final readonly class EpisodeReleaseScheduleObserver
{
    public function __construct(
        private ReleaseCalendarSchema $schema,
        private ReleaseCalendarCacheInvalidator $cache,
        private ReleaseCalendarNotificationService $notifications,
        private ReleaseScheduleIdentity $identity,
    ) {}

    public function saved(Episode $episode): void
    {
        if (! $this->schema->ready() || (! $episode->wasRecentlyCreated && ! $episode->wasChanged('released_at'))) {
            return;
        }

        $episode->loadMissing('season:id,catalog_title_id,number');
        $type = $episode->kind->value === 'special'
            ? ReleaseScheduleEntryType::SpecialRelease
            : ReleaseScheduleEntryType::EpisodeRelease;
        $logicalKey = $this->identity->key(
            $type,
            $episode->season->catalog_title_id,
            $episode->season_id,
            $episode->id,
        );
        $entry = ReleaseScheduleEntry::query()->where('logical_key', $logicalKey)->first();

        if ($entry?->is_locked || ($entry !== null && $entry->source->priority() > ReleaseScheduleSource::Importer->priority())) {
            return;
        }

        if ($episode->released_at === null) {
            if ($entry instanceof ReleaseScheduleEntry && $entry->is_public) {
                $previous = $entry->replicate();
                $entry->forceFill(['is_public' => false, 'revision' => $entry->revision + 1])->save();
                ReleaseScheduleCorrection::query()->firstOrCreate(
                    ['release_schedule_entry_id' => $entry->id, 'revision' => $entry->revision],
                    [
                        'actor_id' => null,
                        'previous_starts_at' => $previous->starts_at,
                        'new_starts_at' => null,
                        'previous_date_value' => $previous->date_value,
                        'new_date_value' => null,
                        'previous_date_end' => $previous->date_end,
                        'new_date_end' => null,
                        'previous_release_year' => $previous->release_year,
                        'new_release_year' => null,
                        'previous_release_month' => $previous->release_month,
                        'new_release_month' => null,
                        'previous_release_quarter' => $previous->release_quarter,
                        'new_release_quarter' => null,
                        'previous_timezone' => $previous->original_timezone,
                        'new_timezone' => $entry->original_timezone,
                        'previous_precision' => $previous->precision,
                        'new_precision' => $entry->precision,
                        'previous_status' => $previous->status,
                        'new_status' => $entry->status,
                        'source' => ReleaseScheduleSource::Importer,
                        'reason_code' => 'episode_release_date_removed',
                    ],
                );
                DB::afterCommit(fn () => $this->cache->scheduleChanged($episode->season->catalog_title_id));
            }

            return;
        }

        $releasedAt = $episode->released_at->startOfDay();
        $saved = ReleaseScheduleEntry::query()->updateOrCreate(
            ['logical_key' => $logicalKey],
            [
                'entry_type' => $type,
                'status' => $releasedAt->isPast() ? ReleaseScheduleStatus::Released : ReleaseScheduleStatus::Confirmed,
                'precision' => ReleaseDatePrecision::ExactDate,
                'source' => ReleaseScheduleSource::Importer,
                'catalog_title_id' => $episode->season->catalog_title_id,
                'season_id' => $episode->season_id,
                'episode_id' => $episode->id,
                'season_number' => $episode->season->number,
                'episode_number' => $episode->number,
                'date_value' => $releasedAt->toDateString(),
                'original_timezone' => 'UTC',
                'is_public' => true,
                'released_at' => $releasedAt->isPast() ? $releasedAt : null,
                'revision' => ($entry?->revision ?? 0) + 1,
            ],
        );
        ReleaseScheduleCorrection::query()->firstOrCreate(
            ['release_schedule_entry_id' => $saved->id, 'revision' => $saved->revision],
            [
                'actor_id' => null,
                'previous_starts_at' => $entry?->starts_at,
                'new_starts_at' => $saved->starts_at,
                'previous_date_value' => $entry?->date_value,
                'new_date_value' => $saved->date_value,
                'previous_date_end' => $entry?->date_end,
                'new_date_end' => $saved->date_end,
                'previous_release_year' => $entry?->release_year,
                'new_release_year' => $saved->release_year,
                'previous_release_month' => $entry?->release_month,
                'new_release_month' => $saved->release_month,
                'previous_release_quarter' => $entry?->release_quarter,
                'new_release_quarter' => $saved->release_quarter,
                'previous_timezone' => $entry?->original_timezone,
                'new_timezone' => $saved->original_timezone,
                'previous_precision' => $entry?->precision,
                'new_precision' => $saved->precision,
                'previous_status' => $entry?->status,
                'new_status' => $saved->status,
                'source' => ReleaseScheduleSource::Importer,
                'reason_code' => 'episode_release_date_sync',
            ],
        );

        DB::afterCommit(function () use ($saved, $entry): void {
            $this->cache->scheduleChanged($saved->catalog_title_id);
            $entry === null ? $this->notifications->announced($saved) : $this->notifications->changed($saved);
        });
    }
}
