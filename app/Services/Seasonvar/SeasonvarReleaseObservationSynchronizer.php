<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseKind;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\SourcePage;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use App\Services\ReleaseCalendar\ReleaseCalendarNotificationService;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SeasonvarReleaseObservationSynchronizer
{
    public function __construct(
        private ReleaseCalendarSchema $schema,
        private ReleaseScheduleIdentity $identity,
        private ReleaseCalendarCacheInvalidator $cache,
        private ReleaseCalendarNotificationService $notifications,
    ) {}

    public function synchronize(
        CatalogTitle $catalogTitle,
        Season $season,
        SourcePage $sourcePage,
    ): ?ReleaseScheduleEntry {
        $observation = $this->observation($catalogTitle, $season, $sourcePage);

        if ($observation === null) {
            return null;
        }

        $result = DB::transaction(function () use ($catalogTitle, $season, $sourcePage, $observation): array {
            $logicalKey = $this->identity->key(
                $observation['type'],
                $catalogTitle->id,
                $season->id,
                $observation['episode']->id,
                translationName: $observation['translation_name'],
            );
            $existing = ReleaseScheduleEntry::query()
                ->where('logical_key', $logicalKey)
                ->lockForUpdate()
                ->first();

            if ($existing?->is_locked
                || ($existing !== null && $existing->source->priority() > ReleaseScheduleSource::Provider->priority())) {
                return ['entry' => null, 'changed' => false, 'created' => false];
            }

            $entry = $existing ?? new ReleaseScheduleEntry;
            $previous = $existing?->replicate();
            $created = ! $existing instanceof ReleaseScheduleEntry;
            $released = ! $observation['date']->isFuture();
            $releasedAt = $released ? $observation['date']->startOfDay() : null;

            $entry->fill([
                'logical_key' => $logicalKey,
                'entry_type' => $observation['type'],
                'status' => $released ? ReleaseScheduleStatus::Released : ReleaseScheduleStatus::Confirmed,
                'precision' => ReleaseDatePrecision::ExactDate,
                'source' => ReleaseScheduleSource::Provider,
                'source_reference' => 'seasonvar:source-page:'.$sourcePage->id,
                'catalog_title_id' => $catalogTitle->id,
                'season_id' => $season->id,
                'episode_id' => $observation['episode']->id,
                'licensed_media_id' => null,
                'season_number' => $season->number,
                'episode_number' => $observation['episode']->number,
                'language_code' => null,
                'translation_name' => $observation['translation_name'],
                'starts_at' => null,
                'date_value' => $observation['date']->toDateString(),
                'date_end' => null,
                'release_year' => null,
                'release_month' => null,
                'release_quarter' => null,
                'original_timezone' => 'UTC',
                'is_estimated' => false,
                'is_public' => true,
                'notifications_enabled' => true,
                'released_at' => $releasedAt,
            ]);

            if (! $created && ! $entry->isDirty()) {
                return ['entry' => $entry, 'changed' => false, 'created' => false];
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
                'source' => ReleaseScheduleSource::Provider,
                'reason_code' => 'seasonvar_release_status_sync',
            ]);

            return ['entry' => $entry, 'changed' => true, 'created' => $created];
        }, attempts: 3);
        $entry = $result['entry'];

        if (! $entry instanceof ReleaseScheduleEntry || ! $result['changed']) {
            return $entry;
        }

        $shouldNotify = $this->shouldNotify($observation['date']);

        $this->afterCommit(function () use ($catalogTitle, $entry, $result, $shouldNotify): void {
            $this->cache->scheduleChanged($catalogTitle->id);

            if ($shouldNotify) {
                $result['created']
                    ? $this->notifications->announced($entry)
                    : $this->notifications->changed($entry);
            }
        });

        return $entry;
    }

    /**
     * @return array{type: ReleaseScheduleEntryType, date: CarbonImmutable, episode: Episode, translation_name: string|null}|null
     */
    private function observation(
        CatalogTitle $catalogTitle,
        Season $season,
        SourcePage $sourcePage,
    ): ?array {
        if (! $this->schema->ready()
            || $season->catalog_title_id !== $catalogTitle->id
            || $sourcePage->source_id !== $catalogTitle->source_id
            || $season->latest_episode_released_at === null
            || $season->episodes_released === null
            || $season->episodes_released <= 0
            || ! filled($season->release_status_text)) {
            return null;
        }

        $date = CarbonImmutable::parse($season->latest_episode_released_at->toDateString(), 'UTC')->startOfDay();
        $rawStatus = Str::squish((string) $season->release_status_text);
        $episodeNumber = (int) $season->episodes_released;

        if (! Str::contains($rawStatus, $date->format('d.m.Y'))
            || preg_match('/(?:^|\D)'.preg_quote((string) $episodeNumber, '/').'\s*сер(?:ия|ии|ий)/iu', $rawStatus) !== 1) {
            return null;
        }

        $episode = Episode::query()
            ->where('season_id', $season->id)
            ->where('kind', ReleaseKind::Regular->value)
            ->where('number', $episodeNumber)
            ->whereNull('deleted_at')
            ->first(['id', 'season_id', 'number']);

        if (! $episode instanceof Episode) {
            return null;
        }

        $translationName = filled($season->translation_name)
            ? Str::limit(Str::squish((string) $season->translation_name), 120, '')
            : null;
        $isSubtitle = $translationName !== null
            && preg_match('/(?:субтитр|subtitles?|subs?)/iu', $translationName) === 1;
        $type = match (true) {
            $isSubtitle => ReleaseScheduleEntryType::SubtitleRelease,
            $translationName !== null => ReleaseScheduleEntryType::TranslationRelease,
            default => ReleaseScheduleEntryType::EpisodeRelease,
        };

        return [
            'type' => $type,
            'date' => $date,
            'episode' => $episode,
            'translation_name' => $type === ReleaseScheduleEntryType::TranslationRelease ? $translationName : null,
        ];
    }

    private function shouldNotify(CarbonImmutable $date): bool
    {
        $recentDays = max(1, (int) config('release-calendar.recent_days', 60));

        return $date->greaterThanOrEqualTo(CarbonImmutable::today('UTC')->subDays($recentDays));
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
