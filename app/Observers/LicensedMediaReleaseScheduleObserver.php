<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationService;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleIdentity;
use Illuminate\Support\Facades\DB;

final readonly class LicensedMediaReleaseScheduleObserver
{
    public function __construct(
        private ReleaseCalendarSchema $schema,
        private ReleaseCalendarCacheInvalidator $cache,
        private ReleaseCalendarNotificationService $notifications,
        private ReleaseScheduleIdentity $identity,
    ) {}

    public function saved(LicensedMedia $media): void
    {
        if (! $this->schema->ready()
            || (! $media->wasRecentlyCreated && ! $media->wasChanged(['published_at', 'status', 'translation_name', 'has_subtitles']))
            || $media->status !== 'published'
            || $media->published_at === null
            || $media->published_at->isFuture()
            || $media->catalog_title_id === null
            || $media->episode_id === null) {
            return;
        }

        $media->loadMissing(['season:id,catalog_title_id,number', 'episode:id,season_id,number']);

        if ($media->season === null || $media->episode === null) {
            return;
        }

        $changes = [];
        $changes[] = $this->syncFact(
            $media,
            ReleaseScheduleEntryType::PortalPublication,
            $this->identity->key(
                ReleaseScheduleEntryType::PortalPublication,
                $media->catalog_title_id,
                $media->season_id,
                $media->episode_id,
                $media->id,
            ),
        );

        if (filled($media->translation_name)) {
            $changes[] = $this->syncFact(
                $media,
                ReleaseScheduleEntryType::TranslationRelease,
                $this->identity->key(
                    ReleaseScheduleEntryType::TranslationRelease,
                    $media->catalog_title_id,
                    $media->season_id,
                    $media->episode_id,
                    $media->id,
                    translationName: $media->translation_name,
                ),
            );
        }

        if ($media->has_subtitles) {
            $changes[] = $this->syncFact(
                $media,
                ReleaseScheduleEntryType::SubtitleRelease,
                $this->identity->key(
                    ReleaseScheduleEntryType::SubtitleRelease,
                    $media->catalog_title_id,
                    $media->season_id,
                    $media->episode_id,
                    $media->id,
                ),
            );
        }

        $changes = array_values(array_filter($changes));

        if ($changes === []) {
            return;
        }

        DB::afterCommit(function () use ($changes, $media): void {
            $this->cache->scheduleChanged($media->catalog_title_id);

            foreach ($changes as $change) {
                $change['created']
                    ? $this->notifications->announced($change['entry'])
                    : $this->notifications->changed($change['entry']);
            }
        });
    }

    /** @return array{entry: ReleaseScheduleEntry, created: bool}|null */
    private function syncFact(LicensedMedia $media, ReleaseScheduleEntryType $type, string $logicalKey): ?array
    {
        $existing = ReleaseScheduleEntry::query()->where('logical_key', $logicalKey)->first();

        if ($existing?->is_locked || ($existing !== null && $existing->source->priority() > ReleaseScheduleSource::Portal->priority())) {
            return null;
        }

        $created = ! $existing instanceof ReleaseScheduleEntry;
        $entry = $existing ?? new ReleaseScheduleEntry;
        $previous = $existing?->replicate();
        $keepExisting = $existing?->starts_at !== null && $existing->starts_at->lessThanOrEqualTo($media->published_at);
        $publishedAt = $keepExisting ? $existing->starts_at : $media->published_at;
        $mediaId = $keepExisting ? $existing->licensed_media_id : $media->id;

        $entry->fill([
            'logical_key' => $logicalKey,
            'entry_type' => $type,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $media->catalog_title_id,
            'season_id' => $media->season_id,
            'episode_id' => $media->episode_id,
            'licensed_media_id' => $mediaId,
            'season_number' => $media->season->number,
            'episode_number' => $media->episode->number,
            'translation_name' => $type === ReleaseScheduleEntryType::TranslationRelease ? $media->translation_name : null,
            'starts_at' => $publishedAt,
            'date_value' => null,
            'date_end' => null,
            'release_year' => null,
            'release_month' => null,
            'release_quarter' => null,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => true,
            'released_at' => $publishedAt,
        ]);

        if (! $created && ! $entry->isDirty()) {
            return null;
        }

        if (! $created) {
            $entry->revision++;
        }

        $entry->save();
        ReleaseScheduleCorrection::query()->firstOrCreate([
            'release_schedule_entry_id' => $entry->id,
            'revision' => $entry->revision,
        ], [
            'actor_id' => null,
            'previous_starts_at' => $previous?->starts_at,
            'new_starts_at' => $entry->starts_at,
            'previous_date_value' => $previous?->date_value,
            'new_date_value' => $entry->date_value,
            'previous_date_end' => $previous?->date_end,
            'new_date_end' => $entry->date_end,
            'previous_release_year' => $previous?->release_year,
            'new_release_year' => $entry->release_year,
            'previous_release_month' => $previous?->release_month,
            'new_release_month' => $entry->release_month,
            'previous_release_quarter' => $previous?->release_quarter,
            'new_release_quarter' => $entry->release_quarter,
            'previous_timezone' => $previous?->original_timezone,
            'new_timezone' => $entry->original_timezone,
            'previous_precision' => $previous?->precision,
            'new_precision' => $entry->precision,
            'previous_status' => $previous?->status,
            'new_status' => $entry->status,
            'source' => ReleaseScheduleSource::Portal,
            'reason_code' => $type->value.'_sync',
        ]);

        return ['entry' => $entry, 'created' => $created];
    }
}
