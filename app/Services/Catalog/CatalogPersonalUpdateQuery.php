<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitleUpdateState;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CatalogPersonalUpdateQuery
{
    public function __construct(
        private PersonalLibrarySchema $schema,
        private ReleaseCalendarSchema $releaseCalendarSchema,
        private ReleaseScheduleVisibility $visibility,
    ) {}

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    public function constrain(Builder $query, User $user, bool $hasUpdates): Builder
    {
        if (! $this->schema->ready() || ! $this->releaseCalendarSchema->ready()) {
            return $hasUpdates ? $query->whereRaw('1 = 0') : $query;
        }

        $updates = $this->unacknowledgedReleaseExists($user);

        return $hasUpdates
            ? $query->whereExists($updates)
            : $query->whereNotExists($updates);
    }

    /** @param Collection<int, CatalogTitleUserState> $states */
    public function hydrateIndicators(User $user, Collection $states): void
    {
        if (! $this->schema->ready() || ! $this->releaseCalendarSchema->ready() || $states->isEmpty()) {
            return;
        }

        $titleIds = $states->pluck('catalog_title_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $acknowledgements = CatalogTitleUpdateState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->pluck('acknowledged_release_id', 'catalog_title_id');
        $latestProgress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->selectRaw('catalog_title_id, max(last_watched_at) as last_watched_at')
            ->groupBy('catalog_title_id')
            ->pluck('last_watched_at', 'catalog_title_id');
        $stateByTitle = $states->keyBy('catalog_title_id');
        $query = ReleaseScheduleEntry::query()
            ->whereIn('release_schedule_entries.catalog_title_id', $titleIds)
            ->where('release_schedule_entries.status', ReleaseScheduleStatus::Released->value)
            ->whereNotNull('release_schedule_entries.released_at')
            ->where(function (Builder $events) use ($acknowledgements, $latestProgress, $states): void {
                foreach ($states as $state) {
                    $titleId = (int) $state->catalog_title_id;
                    $acknowledgedReleaseId = $acknowledgements->get($titleId);

                    $events->orWhere(function (Builder $titleEvents) use (
                        $acknowledgedReleaseId,
                        $latestProgress,
                        $state,
                        $titleId,
                    ): void {
                        $titleEvents->where('release_schedule_entries.catalog_title_id', $titleId);

                        if ($acknowledgedReleaseId !== null) {
                            $titleEvents->where('release_schedule_entries.id', '>', (int) $acknowledgedReleaseId);

                            return;
                        }

                        $baseline = $latestProgress->get($titleId) ?? $state->updated_at;

                        if ($baseline !== null) {
                            $titleEvents->where('release_schedule_entries.released_at', '>', $baseline);
                        }
                    });
                }
            })
            ->select([
                'release_schedule_entries.id',
                'release_schedule_entries.catalog_title_id',
                'release_schedule_entries.entry_type',
                'release_schedule_entries.released_at',
            ])
            ->orderBy('release_schedule_entries.id');
        $this->visibility->constrain($query, $user);

        $labelsByTitle = [];

        foreach ($query->cursor() as $entry) {
            $titleId = (int) $entry->catalog_title_id;
            $state = $stateByTitle->get($titleId);

            if (! $state instanceof CatalogTitleUserState) {
                continue;
            }

            $acknowledgedReleaseId = $acknowledgements->get($titleId);

            if ($acknowledgedReleaseId !== null) {
                if ($entry->id <= (int) $acknowledgedReleaseId) {
                    continue;
                }
            } else {
                $baseline = $latestProgress->get($titleId) ?? $state->updated_at;

                if ($baseline !== null && $entry->released_at?->lessThanOrEqualTo($baseline)) {
                    continue;
                }
            }

            $type = $entry->entry_type;

            if (! $type instanceof ReleaseScheduleEntryType) {
                continue;
            }

            $labelsByTitle[$titleId][$type->value] = $type->label();
        }

        $states->each(function (CatalogTitleUserState $state) use ($labelsByTitle): void {
            $state->setAttribute(
                'personal_update_labels',
                array_values($labelsByTitle[(int) $state->catalog_title_id] ?? []),
            );
        });
    }

    private function unacknowledgedReleaseExists(User $user): \Illuminate\Database\Query\Builder
    {
        $entry = ReleaseScheduleEntry::query()
            ->whereColumn(
                'release_schedule_entries.catalog_title_id',
                'catalog_title_user_states.catalog_title_id',
            )
            ->where('release_schedule_entries.status', ReleaseScheduleStatus::Released->value)
            ->whereNotNull('release_schedule_entries.released_at');
        $this->visibility->constrain($entry, $user);

        $acknowledgement = CatalogTitleUpdateState::query()
            ->where('catalog_title_update_states.user_id', $user->id)
            ->whereColumn(
                'catalog_title_update_states.catalog_title_id',
                'catalog_title_user_states.catalog_title_id',
            );
        $newerProgress = EpisodeViewProgress::query()
            ->where('episode_view_progress.user_id', $user->id)
            ->whereColumn(
                'episode_view_progress.catalog_title_id',
                'catalog_title_user_states.catalog_title_id',
            )
            ->whereColumn(
                'episode_view_progress.last_watched_at',
                '>=',
                'release_schedule_entries.released_at',
            );

        $entry->where(function (Builder $release) use ($acknowledgement, $newerProgress): void {
            $release
                ->whereExists(
                    (clone $acknowledgement)
                        ->whereColumn(
                            'catalog_title_update_states.acknowledged_release_id',
                            '<',
                            'release_schedule_entries.id',
                        )
                        ->selectRaw('1')
                        ->toBase(),
                )
                ->orWhere(function (Builder $withoutAcknowledgement) use ($acknowledgement, $newerProgress): void {
                    $withoutAcknowledgement
                        ->whereNotExists((clone $acknowledgement)->selectRaw('1')->toBase())
                        ->whereColumn(
                            'release_schedule_entries.released_at',
                            '>',
                            'catalog_title_user_states.updated_at',
                        )
                        ->whereNotExists($newerProgress->selectRaw('1')->toBase());
                });
        });

        return $entry->selectRaw('1')->toBase();
    }
}
