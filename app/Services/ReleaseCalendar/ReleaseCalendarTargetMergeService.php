<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseCalendarSubscription;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReleaseCalendarTargetMergeService
{
    public function __construct(
        private ReleaseCalendarSchema $schema,
        private ReleaseCalendarCacheInvalidator $cache,
        private ReleaseScheduleIdentity $identity,
    ) {}

    public function moveTitle(CatalogTitle $source, CatalogTitle $target): void
    {
        if (! $this->schema->ready() || $source->is($target)) {
            return;
        }

        $this->moveEntries(
            fn (Builder $query): Builder => $query->where('catalog_title_id', $source->id),
            ['catalog_title_id' => $target->id],
        );

        ReleaseCalendarSubscription::query()->where('catalog_title_id', $source->id)
            ->chunkById(200, function ($subscriptions) use ($target): void {
                foreach ($subscriptions as $subscription) {
                    $canonical = ReleaseCalendarSubscription::query()->where([
                        'user_id' => $subscription->user_id,
                        'catalog_title_id' => $target->id,
                    ])->first();
                    $fields = [
                        'premiere_notifications', 'season_notifications', 'episode_notifications',
                        'translation_notifications', 'subtitle_notifications',
                        'portal_publication_notifications', 'date_change_notifications',
                    ];

                    if (! $canonical instanceof ReleaseCalendarSubscription) {
                        $canonical = new ReleaseCalendarSubscription([
                            'user_id' => $subscription->user_id,
                            'catalog_title_id' => $target->id,
                            'version' => $subscription->version + 1,
                        ]);

                        foreach ($fields as $field) {
                            $canonical->setAttribute($field, $subscription->getAttribute($field));
                        }
                    } else {
                        foreach ($fields as $field) {
                            $canonical->setAttribute(
                                $field,
                                (bool) $canonical->getAttribute($field) || (bool) $subscription->getAttribute($field),
                            );
                        }

                        $canonical->version = max($canonical->version, $subscription->version) + 1;
                    }

                    $canonical->save();
                    $subscription->delete();
                }
            });
        $this->cache->scheduleChanged($target->id);
    }

    public function moveSeason(Season $source, Season $target): void
    {
        if (! $this->schema->ready() || $source->is($target)) {
            return;
        }

        $this->moveEntries(
            fn (Builder $query): Builder => $query->where('season_id', $source->id),
            ['catalog_title_id' => $target->catalog_title_id, 'season_id' => $target->id, 'season_number' => $target->number],
        );
        $this->cache->scheduleChanged($target->catalog_title_id);
    }

    public function moveEpisode(Episode $source, Episode $target, CatalogTitle $catalogTitle, Season $season): void
    {
        if (! $this->schema->ready() || $source->is($target)) {
            return;
        }

        $this->moveEntries(
            fn (Builder $query): Builder => $query->where('episode_id', $source->id),
            [
                'catalog_title_id' => $catalogTitle->id,
                'season_id' => $season->id,
                'episode_id' => $target->id,
                'season_number' => $season->number,
                'episode_number' => $target->number,
            ],
        );
        $this->cache->scheduleChanged($catalogTitle->id);
    }

    public function moveMedia(LicensedMedia $source, LicensedMedia $target): void
    {
        if (! $this->schema->ready() || $source->is($target)) {
            return;
        }

        $this->moveEntries(
            fn (Builder $query): Builder => $query->where('licensed_media_id', $source->id),
            [
                'catalog_title_id' => $target->catalog_title_id,
                'season_id' => $target->season_id,
                'episode_id' => $target->episode_id,
                'licensed_media_id' => $target->id,
            ],
        );
        $this->cache->scheduleChanged($target->catalog_title_id);
    }

    /** @param callable(Builder<ReleaseScheduleEntry>): Builder<ReleaseScheduleEntry> $scope
     * @param  array<string, int|null>  $targets
     */
    private function moveEntries(callable $scope, array $targets): void
    {
        $scope(ReleaseScheduleEntry::query())->orderBy('id')->chunkById(200, function ($entries) use ($targets): void {
            foreach ($entries as $entry) {
                $catalogTitleId = (int) ($targets['catalog_title_id'] ?? $entry->catalog_title_id);
                $seasonId = array_key_exists('season_id', $targets) ? $targets['season_id'] : $entry->season_id;
                $episodeId = array_key_exists('episode_id', $targets) ? $targets['episode_id'] : $entry->episode_id;
                $mediaId = array_key_exists('licensed_media_id', $targets) ? $targets['licensed_media_id'] : $entry->licensed_media_id;
                $logicalKey = $this->identity->key(
                    $entry->entry_type,
                    $catalogTitleId,
                    $seasonId,
                    $episodeId,
                    $mediaId,
                    $entry->language_code,
                    $entry->translation_name,
                );
                $duplicate = ReleaseScheduleEntry::query()
                    ->where('id', '!=', $entry->id)
                    ->where('logical_key', $logicalKey);

                $previousStatus = $entry->status;
                $previousStartsAt = $entry->starts_at;
                $previousDateValue = $entry->date_value;
                $previousDateEnd = $entry->date_end;
                $previousReleaseYear = $entry->release_year;
                $previousReleaseMonth = $entry->release_month;
                $previousReleaseQuarter = $entry->release_quarter;
                $previousTimezone = $entry->original_timezone;
                $previousPrecision = $entry->precision;

                if ($duplicate->exists()) {
                    $entry->forceFill([
                        ...$targets,
                        'is_public' => false,
                        'notifications_enabled' => false,
                        'status' => ReleaseScheduleStatus::Cancelled,
                        'revision' => $entry->revision + 1,
                    ])->save();

                } else {
                    $entry->forceFill([
                        ...$targets,
                        'logical_key' => $logicalKey,
                        'revision' => $entry->revision + 1,
                    ])->save();
                }

                ReleaseScheduleCorrection::query()->firstOrCreate(
                    ['release_schedule_entry_id' => $entry->id, 'revision' => $entry->revision],
                    [
                        'actor_id' => null,
                        'previous_starts_at' => $previousStartsAt,
                        'new_starts_at' => $entry->starts_at,
                        'previous_date_value' => $previousDateValue,
                        'new_date_value' => $entry->date_value,
                        'previous_date_end' => $previousDateEnd,
                        'new_date_end' => $entry->date_end,
                        'previous_release_year' => $previousReleaseYear,
                        'new_release_year' => $entry->release_year,
                        'previous_release_month' => $previousReleaseMonth,
                        'new_release_month' => $entry->release_month,
                        'previous_release_quarter' => $previousReleaseQuarter,
                        'new_release_quarter' => $entry->release_quarter,
                        'previous_timezone' => $previousTimezone,
                        'new_timezone' => $entry->original_timezone,
                        'previous_precision' => $previousPrecision,
                        'new_precision' => $entry->precision,
                        'previous_status' => $previousStatus,
                        'new_status' => $entry->status,
                        'source' => $entry->source,
                        'reason_code' => 'target_merged',
                    ],
                );
            }
        });
    }
}
