<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\User;
use App\Support\PlainText;
use App\ValueObjects\ReleaseDateValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReleaseScheduleService
{
    public function __construct(
        private ReleaseCalendarCacheInvalidator $cache,
        private ReleaseCalendarNotificationService $notifications,
        private ReleaseScheduleIdentity $identity,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(?ReleaseScheduleEntry $entry, array $data, User $actor): ReleaseScheduleEntry
    {
        $date = ReleaseDateValue::fromValidated($data);
        $target = $this->target($data);
        $creating = $entry === null;
        $changed = true;
        $notify = $creating;

        $saved = DB::transaction(function () use ($entry, $data, $actor, $date, $target, &$changed, &$notify): ReleaseScheduleEntry {
            if ($entry !== null) {
                $entry = ReleaseScheduleEntry::query()->lockForUpdate()->findOrFail($entry->id);
            } else {
                $entry = new ReleaseScheduleEntry;
            }

            $type = ReleaseScheduleEntryType::from((string) $data['entry_type']);
            $status = ReleaseScheduleStatus::from((string) $data['status']);
            $source = ReleaseScheduleSource::from((string) $data['source']);
            $previous = $entry->exists ? $entry->replicate() : null;
            $this->validateTypeTarget($type, $target);

            if (($date->estimated && in_array($status, [ReleaseScheduleStatus::Confirmed, ReleaseScheduleStatus::Released], true))
                || ($status === ReleaseScheduleStatus::Estimated && ! $date->estimated)) {
                throw ValidationException::withMessages(['status' => [__('calendar.errors.invalid_estimate_state')]]);
            }

            if ($entry->exists && $entry->status !== $status && ! in_array($status, $entry->status->transitions(), true)) {
                throw ValidationException::withMessages(['status' => [__('calendar.errors.invalid_transition')]]);
            }

            $logicalKey = $this->identity->key(
                $type,
                $target['title']->id,
                $target['season']?->id,
                $target['episode']?->id,
                $target['media']?->id,
                is_string($data['language_code'] ?? null) ? $data['language_code'] : null,
                is_string($data['translation_name'] ?? null) ? $data['translation_name'] : null,
            );

            if (ReleaseScheduleEntry::query()->where('logical_key', $logicalKey)->when($entry->exists, fn ($query) => $query->whereKeyNot($entry->id))->exists()) {
                throw ValidationException::withMessages(['entry_type' => [__('calendar.errors.duplicate_entry')]]);
            }
            $attributes = [
                'logical_key' => $logicalKey,
                'entry_type' => $type,
                'status' => $status,
                'precision' => $date->precision,
                'source' => $source,
                'source_reference' => filled($data['source_reference'] ?? null) ? PlainText::clean($data['source_reference'], 191) : null,
                'catalog_title_id' => $target['title']->id,
                'season_id' => $target['season']?->id,
                'episode_id' => $target['episode']?->id,
                'licensed_media_id' => $target['media']?->id,
                'season_number' => $target['season']?->number,
                'episode_number' => $target['episode']?->number,
                'language_code' => filled($data['language_code'] ?? null) ? mb_strtolower(PlainText::clean($data['language_code'], 16)) : null,
                'translation_name' => filled($data['translation_name'] ?? null) ? PlainText::clean($data['translation_name'], 120) : null,
                ...$date->attributes(),
                'is_locked' => (bool) ($data['is_locked'] ?? false),
                'is_public' => (bool) ($data['is_public'] ?? true),
                'notifications_enabled' => (bool) ($data['notifications_enabled'] ?? true),
                'released_at' => $status === ReleaseScheduleStatus::Released ? ($entry->released_at ?? now()) : null,
            ];

            $entry->fill($attributes);
            $hasNotes = filled($data['reason_code'] ?? null) || filled($data['public_note'] ?? null) || filled($data['private_note'] ?? null);

            if ($entry->exists) {
                $notify = $entry->isDirty([
                    'entry_type', 'status', 'precision', 'date_value', 'date_end',
                    'release_year', 'release_month', 'release_quarter', 'is_public',
                ]);
                $previousStart = $entry->getOriginal('starts_at');
                $newStart = $entry->starts_at;

                if ($entry->isDirty('starts_at')) {
                    $notify = $notify
                        || $previousStart === null
                        || $newStart === null
                        || abs(CarbonImmutable::parse($previousStart)->diffInMinutes($newStart, false))
                            >= max(1, (int) config('release-calendar.date_change_notification_threshold_minutes', 30));
                }

                if (! $entry->isDirty() && ! $hasNotes) {
                    $changed = false;

                    return $entry;
                }

                $entry->revision++;
            }

            $entry->save();

            ReleaseScheduleCorrection::query()->create([
                'release_schedule_entry_id' => $entry->id,
                'actor_id' => $actor->id,
                'revision' => $entry->revision,
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
                'source' => $entry->source,
                'reason_code' => filled($data['reason_code'] ?? null) ? PlainText::clean($data['reason_code'], 48) : null,
                'public_note' => filled($data['public_note'] ?? null) ? PlainText::clean($data['public_note'], 2_000) : null,
                'private_note' => filled($data['private_note'] ?? null) ? PlainText::clean($data['private_note'], 4_000) : null,
            ]);

            return $entry;
        }, attempts: 3);

        if ($changed) {
            $this->cache->scheduleChanged($saved->catalog_title_id);

            if ($notify) {
                $creating ? $this->notifications->announced($saved) : $this->notifications->changed($saved);
            }
        }

        return $saved->fresh(['catalogTitle', 'season', 'episode', 'licensedMedia']) ?? $saved;
    }

    /** @param array<string, mixed> $data
     * @return array{title: CatalogTitle, season: Season|null, episode: Episode|null, media: LicensedMedia|null}
     */
    private function target(array $data): array
    {
        $title = CatalogTitle::query()->findOrFail((int) $data['catalog_title_id']);
        $season = filled($data['season_id'] ?? null) ? Season::query()->findOrFail((int) $data['season_id']) : null;
        $episode = filled($data['episode_id'] ?? null) ? Episode::query()->findOrFail((int) $data['episode_id']) : null;
        $media = filled($data['licensed_media_id'] ?? null) ? LicensedMedia::query()->findOrFail((int) $data['licensed_media_id']) : null;

        if (($season !== null && $season->catalog_title_id !== $title->id)
            || ($episode !== null && ($season === null || $episode->season_id !== $season->id))
            || ($media !== null && $media->catalog_title_id !== $title->id)
            || ($media !== null && $media->season_id !== null && $media->season_id !== $season?->id)
            || ($media !== null && $media->episode_id !== null && $media->episode_id !== $episode?->id)) {
            throw ValidationException::withMessages(['catalog_title_id' => [__('calendar.errors.invalid_target')]]);
        }

        return compact('title', 'season', 'episode', 'media');
    }

    /** @param array{title: CatalogTitle, season: Season|null, episode: Episode|null, media: LicensedMedia|null} $target */
    private function validateTypeTarget(ReleaseScheduleEntryType $type, array $target): void
    {
        $valid = match ($type) {
            ReleaseScheduleEntryType::SerialPremiere => $target['season'] === null && $target['episode'] === null && $target['media'] === null,
            ReleaseScheduleEntryType::SeasonPremiere => $target['season'] !== null && $target['episode'] === null && $target['media'] === null,
            ReleaseScheduleEntryType::EpisodeRelease, ReleaseScheduleEntryType::SpecialRelease => $target['episode'] !== null,
            ReleaseScheduleEntryType::TranslationRelease, ReleaseScheduleEntryType::SubtitleRelease => $target['episode'] !== null,
            ReleaseScheduleEntryType::PortalPublication => $target['media'] !== null && $target['episode'] !== null,
            ReleaseScheduleEntryType::QualityUpgrade => $target['media'] !== null,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['entry_type' => [__('calendar.errors.invalid_target')]]);
        }
    }
}
