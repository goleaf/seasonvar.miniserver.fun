<?php

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SourcePage;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class SeasonvarCatalogMetadataBackfill
{
    public function __construct(
        private readonly SeasonvarCatalogParser $parser,
        private readonly SeasonvarCatalogRelationSyncer $relationSyncer,
        private readonly SeasonvarRelationMetadataNormalizer $relationMetadata,
        private readonly SeasonvarDatabaseTransaction $databaseTransaction,
        private readonly CatalogSearchIndexer $searchIndexer,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{pages_checked: int, pages_updated: int, titles_checked: int, titles_updated: int, relations_attached: int, failed: int}
     */
    public function run(?callable $progress = null): array
    {
        $result = $this->emptyResult();
        $attemptedTitleIds = [];
        $updatedTitleIds = [];

        $this->report($progress, 'seasonvar-metadata-backfill-started', [
            'metadata_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'page_limit' => $this->pageLimit(),
            'title_limit' => $this->titleLimit(),
        ]);

        $pages = SourcePage::query()
            ->select([
                'id',
                'source_id',
                'url',
                'url_hash',
                'page_type',
                'metadata_parser_version',
                'metadata_attempted_version',
            ])
            ->where('page_type', 'serial')
            ->where('metadata_parser_version', '<', SeasonvarCatalogParser::METADATA_VERSION)
            ->where('metadata_attempted_version', '<', SeasonvarCatalogParser::METADATA_VERSION)
            ->whereHas('latestSnapshot')
            ->where(function ($query): void {
                $query
                    ->whereIn(
                        'id',
                        CatalogTitle::query()->select('source_page_id')->whereNotNull('source_page_id'),
                    )
                    ->orWhereIn(
                        'url_hash',
                        Season::query()->select('source_url_hash')->whereNotNull('source_url_hash'),
                    );
            })
            ->with([
                'latestSnapshot' => fn ($query) => $query->select([
                    'source_page_snapshots.id',
                    'source_page_snapshots.source_page_id',
                    'source_page_snapshots.html',
                    'source_page_snapshots.captured_at',
                ]),
                'catalogTitle:id,source_id,source_page_id,relation_metadata_version',
                'linkedSeasons:id,catalog_title_id,source_url_hash',
                'linkedSeasons.catalogTitle:id,source_id,relation_metadata_version',
            ])
            ->lazyById($this->pageChunkSize())
            ->take($this->pageLimit());

        foreach ($pages as $page) {
            $result['pages_checked']++;
            $title = $this->titleForPage($page);

            if ($title === null || $page->latestSnapshot === null) {
                continue;
            }

            $attemptedTitleIds[$title->id] = true;

            try {
                $data = SeasonvarCatalogData::fromParsed(
                    $this->parser->parse($page->latestSnapshot->html, $page->url),
                );
            } catch (ValidationException|InvalidArgumentException $exception) {
                try {
                    $this->databaseTransaction->run(
                        fn () => $page->update([
                            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
                        ]),
                        attempts: $this->transactionAttempts(),
                        baseDelayMilliseconds: $this->transactionRetryDelayMilliseconds(),
                        progress: $progress,
                    );
                } catch (Throwable $databaseException) {
                    $result['failed']++;
                    $this->reportFailure($progress, 'seasonvar-metadata-page-failed', $page->id, $databaseException);

                    continue;
                }

                $result['failed']++;
                $this->reportFailure($progress, 'seasonvar-metadata-page-rejected', $page->id, $exception);

                continue;
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->reportFailure($progress, 'seasonvar-metadata-page-failed', $page->id, $exception);

                continue;
            }

            try {
                $title->loadMissing([
                    'licensedMedia:id,catalog_title_id,variant_type,variant_name,translation_name',
                    'seasons:id,catalog_title_id,translation_name',
                ]);
                $taxonomies = [
                    ...$data->taxonomies,
                    ...$this->derivedTranslationTaxonomies($title),
                ];
                $presence = $this->parser->metadataPresence($data->taxonomies, $data->parseMeta);
                $titleWasStale = $title->relation_metadata_version < SeasonvarCatalogParser::METADATA_VERSION;

                $attached = $this->databaseTransaction->run(
                    function () use ($page, $title, $taxonomies, $presence, $progress): int {
                        $attached = $this->attachedCount(
                            $this->relationSyncer->sync($title, $taxonomies, $progress),
                        );
                        $page->update([
                            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
                            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
                            'metadata_parsed_at' => now(),
                            'metadata_presence' => $presence,
                        ]);
                        $title->update([
                            'relation_metadata_version' => SeasonvarCatalogParser::METADATA_VERSION,
                        ]);

                        return $attached;
                    },
                    attempts: $this->transactionAttempts(),
                    baseDelayMilliseconds: $this->transactionRetryDelayMilliseconds(),
                    progress: $progress,
                );

                $result['pages_updated']++;
                $result['titles_checked']++;
                $result['titles_updated'] += $titleWasStale ? 1 : 0;
                $result['relations_attached'] += $attached;
                $updatedTitleIds[$title->id] = true;
                $this->report($progress, 'seasonvar-metadata-page-complete', [
                    'source_page_id' => $page->id,
                    'catalog_title_id' => $title->id,
                    'relations_attached' => $attached,
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->reportFailure($progress, 'seasonvar-metadata-page-failed', $page->id, $exception);
            }
        }

        $titles = CatalogTitle::query()
            ->select(['id', 'source_id', 'relation_metadata_version'])
            ->where('relation_metadata_version', '<', SeasonvarCatalogParser::METADATA_VERSION)
            ->when(
                $attemptedTitleIds !== [],
                fn ($query) => $query->whereNotIn('id', array_keys($attemptedTitleIds)),
            )
            ->with([
                'licensedMedia:id,catalog_title_id,variant_type,variant_name,translation_name',
                'seasons:id,catalog_title_id,translation_name',
            ])
            ->lazyById($this->titleChunkSize())
            ->take($this->titleLimit());

        foreach ($titles as $title) {
            $result['titles_checked']++;

            try {
                $attached = $this->databaseTransaction->run(
                    function () use ($title, $progress): int {
                        $attached = $this->attachedCount(
                            $this->relationSyncer->sync(
                                $title,
                                $this->derivedTranslationTaxonomies($title),
                                $progress,
                            ),
                        );
                        $title->update([
                            'relation_metadata_version' => SeasonvarCatalogParser::METADATA_VERSION,
                        ]);

                        return $attached;
                    },
                    attempts: $this->transactionAttempts(),
                    baseDelayMilliseconds: $this->transactionRetryDelayMilliseconds(),
                    progress: $progress,
                );

                $result['titles_updated']++;
                $result['relations_attached'] += $attached;
                $updatedTitleIds[$title->id] = true;
                $this->report($progress, 'seasonvar-metadata-title-complete', [
                    'catalog_title_id' => $title->id,
                    'relations_attached' => $attached,
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->reportFailure($progress, 'seasonvar-metadata-title-failed', $title->id, $exception);
            }
        }

        if ($updatedTitleIds !== []) {
            $this->searchIndexer->synchronizeTitleIds(array_keys($updatedTitleIds));
        }

        $this->report($progress, 'seasonvar-metadata-backfill-complete', $result);

        return $result;
    }

    private function titleForPage(SourcePage $page): ?CatalogTitle
    {
        if ($page->catalogTitle !== null) {
            return $page->catalogTitle;
        }

        return $page->linkedSeasons
            ->sortBy('id')
            ->first(fn ($season): bool => $season->catalogTitle !== null)
            ?->catalogTitle;
    }

    /**
     * @return list<array{type: string, name: string, source_url: null}>
     */
    private function derivedTranslationTaxonomies(CatalogTitle $title): array
    {
        return $title->licensedMedia
            ->flatMap(fn (LicensedMedia $media): array => [
                $media->variant_name,
                $media->translation_name,
            ])
            ->concat($title->seasons->pluck('translation_name'))
            ->map(fn (mixed $name): ?string => is_string($name)
                ? $this->relationMetadata->translation($name)
                : null)
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->map(fn (string $name): array => [
                'type' => 'translation',
                'name' => $name,
                'source_url' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{attached_count?: int}>  $syncResult
     */
    private function attachedCount(array $syncResult): int
    {
        return collect($syncResult)->sum(fn (array $item): int => (int) ($item['attached_count'] ?? 0));
    }

    /**
     * @return array{pages_checked: int, pages_updated: int, titles_checked: int, titles_updated: int, relations_attached: int, failed: int}
     */
    private function emptyResult(): array
    {
        return [
            'pages_checked' => 0,
            'pages_updated' => 0,
            'titles_checked' => 0,
            'titles_updated' => 0,
            'relations_attached' => 0,
            'failed' => 0,
        ];
    }

    private function pageChunkSize(): int
    {
        return max(1, (int) config('seasonvar.metadata_backfill.page_chunk_size', 50));
    }

    private function pageLimit(): int
    {
        return max(1, (int) config('seasonvar.metadata_backfill.page_limit', 200));
    }

    private function titleChunkSize(): int
    {
        return max(1, (int) config('seasonvar.metadata_backfill.title_chunk_size', 50));
    }

    private function titleLimit(): int
    {
        return max(1, (int) config('seasonvar.metadata_backfill.title_limit', 200));
    }

    private function transactionAttempts(): int
    {
        return max(1, (int) config('seasonvar.import.transaction_attempts', 5));
    }

    private function transactionRetryDelayMilliseconds(): int
    {
        return max(0, (int) config('seasonvar.import.transaction_retry_delay_ms', 250));
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function reportFailure(?callable $progress, string $event, int $recordId, Throwable $exception): void
    {
        $this->report($progress, $event, [
            'record_id' => $recordId,
            'exception' => $exception::class,
        ]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
