<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarPageType;
use App\Enums\SeasonvarSourceAvailability;
use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SeasonvarRefreshPlanner
{
    public function __construct(private readonly SeasonvarPageHandlerRegistry $handlers) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  list<string>|null  $pageTypes
     * @return iterable<Collection<int, SourcePage>>
     */
    public function pageChunksForImportCycle(
        int $chunkSize,
        Carbon $refreshAfter,
        ?int $importRunId = null,
        ?callable $progress = null,
        ?array $pageTypes = null,
    ): iterable {
        $chunkSize = max(1, $chunkSize);
        $totalSelected = 0;
        $selectedIds = [];
        $processingTypes = $this->handlers->processingTypes($pageTypes);

        foreach ($this->metadataPageChunks($processingTypes, $importRunId, false, $progress) as $pages) {
            $totalSelected += $pages->count();
            yield $pages;
        }

        if (! in_array(SeasonvarPageType::Serial->value, $processingTypes, true)) {
            return;
        }

        $attentionPages = $this->rejectAlreadySelectedPages(
            $this->baseQuery($importRunId)
                ->where('parse_status', 'parsed')
                ->where('import_status', 'missing_data')
                ->orderBy('retry_after_at')
                ->orderBy('last_imported_at')
                ->orderBy('id')
                ->limit($chunkSize)
                ->get(),
            $selectedIds,
        );

        if ($attentionPages->isNotEmpty()) {
            $totalSelected += $attentionPages->count();

            $this->report($progress, 'seasonvar-refresh-candidates-selected', [
                'reason' => 'needs_attention',
                'selected' => $attentionPages->count(),
                'reason_selected' => $attentionPages->count(),
                'total_selected' => $totalSelected,
                'chunk_size' => $chunkSize,
            ]);

            yield $attentionPages;
        }

        foreach ($this->candidateQueries($refreshAfter) as $reason => $callback) {
            $reasonSelected = 0;
            $query = $this->baseQuery($importRunId)->tap($callback);
            $pagesForReason = $query->lazyById($chunkSize);

            if ($reason === 'stale_metadata') {
                $pagesForReason = $pagesForReason->take($this->metadataRefreshLimit());
            }

            foreach ($pagesForReason->chunk($chunkSize) as $pages) {
                $pages = $pages->collect();
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
     * @param  list<string>|null  $pageTypes
     * @return iterable<Collection<int, SourcePage>>
     */
    public function forcedPageChunks(
        int $chunkSize,
        ?int $importRunId = null,
        ?callable $progress = null,
        ?array $pageTypes = null,
    ): iterable {
        $chunkSize = max(1, $chunkSize);
        $totalSelected = 0;
        $processingTypes = $this->handlers->processingTypes($pageTypes);

        foreach ($this->metadataPageChunks($processingTypes, $importRunId, true, $progress) as $pages) {
            $totalSelected += $pages->count();
            yield $pages;
        }

        if (! in_array(SeasonvarPageType::Serial->value, $processingTypes, true)) {
            return;
        }

        foreach ($this->baseQuery($importRunId)->lazyById($chunkSize)->chunk($chunkSize) as $pages) {
            $pages = $pages->collect();
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
     * @param  list<string>  $processingTypes
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return iterable<Collection<int, SourcePage>>
     */
    private function metadataPageChunks(array $processingTypes, ?int $importRunId, bool $force, ?callable $progress): iterable
    {
        foreach ($processingTypes as $typeValue) {
            $type = SeasonvarPageType::from($typeValue);

            if ($type === SeasonvarPageType::Serial) {
                continue;
            }

            $chunkSize = $this->handlers->chunkSize($type);
            $refreshAfter = now()->subHours($this->handlers->refreshHours($type));
            $query = SourcePage::query()
                ->with('source')
                ->where('page_type', $type->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('import_claim_token')
                        ->orWhereNull('import_claim_expires_at')
                        ->orWhere('import_claim_expires_at', '<=', now());
                })
                ->when($importRunId !== null, function (Builder $query) use ($importRunId): void {
                    $query->where(function (Builder $query) use ($importRunId): void {
                        $query->whereNull('last_import_run_id')
                            ->orWhere('last_import_run_id', '!=', $importRunId);
                    });
                })
                ->when(! $force, function (Builder $query) use ($refreshAfter): void {
                    $query->where(function (Builder $query) use ($refreshAfter): void {
                        $query->where('parse_status', 'pending')
                            ->orWhere(function (Builder $query): void {
                                $query->where('parse_status', 'failed')
                                    ->where(function (Builder $query): void {
                                        $query->whereNull('retry_after_at')
                                            ->orWhere('retry_after_at', '<=', now());
                                    });
                            })
                            ->orWhere(function (Builder $query) use ($refreshAfter): void {
                                $query->where('parse_status', 'parsed')
                                    ->where(function (Builder $query) use ($refreshAfter): void {
                                        $query->whereNull('last_imported_at')
                                            ->orWhere('last_imported_at', '<=', $refreshAfter);
                                    });
                            });
                    })->where(function (Builder $query): void {
                        $query->whereNull('retry_after_at')
                            ->orWhere('retry_after_at', '<=', now());
                    });
                })
                ->orderBy('id');

            foreach ($query->lazyById($chunkSize)->chunk($chunkSize) as $pages) {
                $pages = $pages->collect()->values();

                $this->report($progress, 'seasonvar-refresh-candidates-selected', [
                    'reason' => $force ? 'force_metadata' : 'metadata_due',
                    'page_type' => $type->value,
                    'selected' => $pages->count(),
                    'chunk_size' => $chunkSize,
                ]);

                yield $pages;
            }
        }
    }

    /**
     * @return Builder<SourcePage>
     */
    private function baseQuery(?int $importRunId): Builder
    {
        return SourcePage::query()
            ->with('source')
            ->where('page_type', 'serial')
            ->where(function (Builder $query): void {
                $query->whereNull('import_claim_token')
                    ->orWhereNull('import_claim_expires_at')
                    ->orWhere('import_claim_expires_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->where('parse_status', '!=', 'parsed')
                    ->orWhere('metadata_parser_version', '>=', SeasonvarCatalogParser::METADATA_VERSION)
                    ->orWhere('metadata_attempted_version', '>=', SeasonvarCatalogParser::METADATA_VERSION)
                    ->orWhereDoesntHave('latestSnapshot');
            })
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
                    $query->whereDoesntHave('publishedMedia');
                }),

            'episodes_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->where(function (Builder $query): void {
                    $query
                        ->whereHas('linkedSeasons.episodes', function (Builder $query): void {
                            $query->whereDoesntHave('publishedMedia');
                        })
                        ->orWhereHas('seasons.episodes', function (Builder $query): void {
                            $query->whereDoesntHave('publishedMedia');
                        });
                }),

            'title_without_video' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query))
                ->whereHas('catalogTitle', function (Builder $query): void {
                    $query->whereDoesntHave('publishedMedia');
                }),

            'missing_data' => fn (Builder $query): Builder => $query
                ->where('import_status', 'missing_data')
                ->where(fn (Builder $query): Builder => $this->dueForMissingDataRetry($query)),

            'provider_region_blocked' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where('provider_availability_status', SeasonvarSourceAvailability::RegionBlocked->value)
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

            'stale_metadata' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where('metadata_parser_version', '<', SeasonvarCatalogParser::METADATA_VERSION)
                ->where(function (Builder $query) use ($refreshAfter): void {
                    $query->whereNull('last_imported_at')
                        ->orWhere('last_imported_at', '<=', $refreshAfter);
                })
                ->where(function (Builder $query): void {
                    $query->where('metadata_attempted_version', '>=', SeasonvarCatalogParser::METADATA_VERSION)
                        ->orWhereDoesntHave('latestSnapshot');
                }),

            'stale' => fn (Builder $query): Builder => $query
                ->where('parse_status', 'parsed')
                ->where('metadata_parser_version', '>=', SeasonvarCatalogParser::METADATA_VERSION)
                ->where(function (Builder $query) use ($refreshAfter): void {
                    $query->whereNull('last_imported_at')
                        ->orWhere('last_imported_at', '<=', $refreshAfter);
                }),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function dueForMissingDataRetry(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('retry_after_at')
                ->orWhere('retry_after_at', '<=', now());
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function unavailableMedia(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', 'unavailable')
                ->orWhereIn('health_status', ['unavailable', 'disabled']);
        });
    }

    private function metadataRefreshLimit(): int
    {
        return max(1, (int) config('seasonvar.metadata_backfill.page_limit', 200));
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
