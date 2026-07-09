<?php

namespace App\Services\Seasonvar;

use App\Models\LicensedMedia;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SeasonvarRefreshPlanner
{
    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return iterable<Collection<int, SourcePage>>
     */
    public function pageChunksForImportCycle(int $chunkSize, Carbon $refreshAfter, ?int $importRunId = null, ?callable $progress = null): iterable
    {
        $chunkSize = max(1, $chunkSize);
        $totalSelected = 0;
        $selectedIds = [];

        foreach ($this->candidateQueries($refreshAfter) as $reason => $callback) {
            $reasonSelected = 0;
            $query = $this->baseQuery($importRunId)->tap($callback);

            foreach ($query->lazyById($chunkSize)->chunk($chunkSize) as $pages) {
                $pages = $pages instanceof Collection ? $pages : $pages->collect();
                $pages = $this->rejectAlreadySelectedPages($pages, $selectedIds);

                if ($pages->isEmpty()) {
                    continue;
                }

                $reasonSelected += $pages->count();
                $totalSelected += $pages->count();

                $this->report($progress, 'seasonvar-refresh-candidates-selected', [
                    'reason' => $reason,
                    'selected' => $pages->count(),
                    'reason_selected' => $reasonSelected,
                    'total_selected' => $totalSelected,
                    'chunk_size' => $chunkSize,
                ]);

                yield $pages->values();
            }
        }
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return iterable<Collection<int, SourcePage>>
     */
    public function forcedPageChunks(int $chunkSize, ?int $importRunId = null, ?callable $progress = null): iterable
    {
        $chunkSize = max(1, $chunkSize);
        $totalSelected = 0;

        foreach ($this->baseQuery($importRunId)->lazyById($chunkSize)->chunk($chunkSize) as $pages) {
            $pages = $pages instanceof Collection ? $pages : $pages->collect();
            $totalSelected += $pages->count();

            $this->report($progress, 'seasonvar-refresh-candidates-selected', [
                'reason' => 'force',
                'selected' => $pages->count(),
                'reason_selected' => $totalSelected,
                'total_selected' => $totalSelected,
                'chunk_size' => $chunkSize,
            ]);

            yield $pages->values();
        }
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @param  array<int, true>  $selectedIds
     * @return Collection<int, SourcePage>
     */
    private function rejectAlreadySelectedPages(Collection $pages, array &$selectedIds): Collection
    {
        return $pages
            ->reject(function (SourcePage $page) use (&$selectedIds): bool {
                $id = (int) $page->id;

                if (isset($selectedIds[$id])) {
                    return true;
                }

                $selectedIds[$id] = true;

                return false;
            })
            ->values();
    }

    /**
     * @return Builder<SourcePage>
     */
    private function baseQuery(?int $importRunId): Builder
    {
        return SourcePage::query()
            ->with('source')
            ->where('page_type', 'serial')
            ->when($importRunId !== null, function (Builder $query) use ($importRunId): Builder {
                return $query->where(function (Builder $query) use ($importRunId): void {
                    $query->whereNull('last_import_run_id')
                        ->orWhere('last_import_run_id', '!=', $importRunId);
                });
            });
    }

    /**
     * @return array<string, callable(Builder<SourcePage>): Builder<SourcePage>>
     */
    private function candidateQueries(Carbon $refreshAfter): array
    {
        return [
            'seasons_without_episodes' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->whereHas('linkedSeasons', function (Builder $query): void {
                    $query->whereDoesntHave('episodes');
                }),

            'seasons_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->whereHas('linkedSeasons', function (Builder $query): void {
                    $query->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
                }),

            'episodes_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->where(function (Builder $query): void {
                    $query
                        ->whereHas('linkedSeasons.episodes', function (Builder $query): void {
                            $query->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
                        })
                        ->orWhereHas('seasons.episodes', function (Builder $query): void {
                            $query->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
                        });
                }),

            'title_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->whereHas('catalogTitle', function (Builder $query): void {
                    $query->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
                }),

            'missing_data' => fn (Builder $query): Builder => $query
                ->where('import_status', 'missing_data')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query)),

            'pending' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'pending'),

            'unavailable_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->where(function (Builder $query): void {
                    $query
                        ->whereHas('catalogTitle.licensedMedia', fn (Builder $query): Builder => $this->unavailableMedia($query))
                        ->orWhereHas('seasons.licensedMedia', fn (Builder $query): Builder => $this->unavailableMedia($query))
                        ->orWhereHas('seasons.episodes.licensedMedia', fn (Builder $query): Builder => $this->unavailableMedia($query));
                }),

            'failed_retry' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'failed')
                ->where(function (Builder $query): void {
                    $query->whereNull('retry_after_at')
                        ->orWhere('retry_after_at', '<=', now());
                }),

            'stale' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(function (Builder $query) use ($refreshAfter): void {
                    $query->whereNull('last_imported_at')
                        ->orWhere('last_imported_at', '<=', $refreshAfter);
                }),
        ];
    }

    /**
     * @return Builder<SourcePage>
     */
    private function dueForMissingDataRetry(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('retry_after_at')
                ->orWhere('retry_after_at', '<=', now());
        });
    }

    /**
     * @return Builder<LicensedMedia>
     */
    private function unavailableMedia(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', 'unavailable')
                ->orWhereIn('check_status', ['check_failed', 'unavailable']);
        });
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
