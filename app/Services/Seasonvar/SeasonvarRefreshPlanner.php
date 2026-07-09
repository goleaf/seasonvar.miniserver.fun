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
     * @return Collection<int, SourcePage>
     */
    public function pagesForImportCycle(int $limit, Carbon $refreshAfter, ?callable $progress = null): Collection
    {
        $limit = max(1, $limit);
        $selected = collect();
        $seenIds = [];

        foreach ($this->candidateQueries($refreshAfter) as $reason => $callback) {
            $remaining = $limit - $selected->count();

            if ($remaining <= 0) {
                break;
            }

            $pages = $this->baseQuery($seenIds)
                ->tap($callback)
                ->limit($remaining)
                ->get();

            foreach ($pages as $page) {
                $seenIds[] = (int) $page->id;
                $selected->push($page);
            }

            $this->report($progress, 'seasonvar-refresh-candidates-selected', [
                'reason' => $reason,
                'selected' => $pages->count(),
                'total_selected' => $selected->count(),
                'limit' => $limit,
            ]);
        }

        return $selected;
    }

    /**
     * @param  list<int>  $excludeIds
     * @return Builder<SourcePage>
     */
    private function baseQuery(array $excludeIds): Builder
    {
        return SourcePage::query()
            ->with('source')
            ->where('page_type', 'serial')
            ->when($excludeIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $excludeIds))
            ->oldest('last_imported_at')
            ->oldest();
    }

    /**
     * @return array<string, callable(Builder<SourcePage>): Builder<SourcePage>>
     */
    private function candidateQueries(Carbon $refreshAfter): array
    {
        return [
            'episodes_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->whereHas('seasons.episodes', function (Builder $query): void {
                    $query->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
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
