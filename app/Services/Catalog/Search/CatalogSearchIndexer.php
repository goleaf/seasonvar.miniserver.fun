<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitleSearchDocument;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CatalogSearchIndexer
{
    public const INDEX_VERSION = 1;

    public function __construct(
        private readonly CatalogSearchDocumentBuilder $documents,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly SeasonvarImportErrorSanitizer $errors,
    ) {}

    /**
     * @param  iterable<int|string>  $titleIds
     * @return array{requested: int, indexed: int, unchanged: int, deleted: int}
     */
    public function indexTitleIds(iterable $titleIds, int $chunkSize = 200): array
    {
        $ids = collect($titleIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $result = [
            'requested' => $ids->count(),
            'indexed' => 0,
            'unchanged' => 0,
            'deleted' => 0,
        ];

        foreach ($ids->chunk(max(1, $chunkSize)) as $chunk) {
            $this->indexChunk($chunk, $result);
        }

        return $result;
    }

    public function sourceCount(): int
    {
        return $this->titles->visibleTo(null)->count();
    }

    /** @param iterable<int|string> $titleIds */
    public function synchronizeTitleIds(iterable $titleIds): bool
    {
        if (! Schema::hasTable('catalog_title_search_documents')) {
            return true;
        }

        try {
            $this->indexTitleIds($titleIds);

            return true;
        } catch (Throwable $exception) {
            if (Schema::hasTable('catalog_search_index_states')) {
                CatalogSearchIndexState::query()
                    ->whereKey(CatalogSearchIndexState::SINGLETON_ID)
                    ->update([
                        'status' => CatalogSearchIndexStatus::Stale,
                        'failed_at' => now(),
                        'last_error' => $this->errors->fromException($exception),
                        'updated_at' => now(),
                    ]);
            }

            return false;
        }
    }

    public function documentCount(): int
    {
        return CatalogTitleSearchDocument::query()->count();
    }

    public function pruneOutOfScopeDocuments(): int
    {
        return CatalogTitleSearchDocument::query()
            ->whereNotIn('catalog_title_id', $this->titles->visibleTo(null)->select('catalog_titles.id'))
            ->delete();
    }

    /**
     * @param  callable(int, array{requested: int, indexed: int, unchanged: int, deleted: int}): void  $progress
     */
    public function rebuildFromCheckpoint(int $checkpointId, int $chunkSize, callable $progress): int
    {
        $lastId = max(0, $checkpointId);

        $this->titles->visibleTo(null)
            ->select('catalog_titles.id')
            ->where('catalog_titles.id', '>', $lastId)
            ->chunkById(max(1, $chunkSize), function (Collection $titles) use (&$lastId, $chunkSize, $progress): void {
                $ids = $titles->pluck('id');
                $result = $this->indexTitleIds($ids, $chunkSize);
                $lastId = (int) $ids->max();
                $progress($lastId, $result);
            }, 'catalog_titles.id', 'id');

        return $lastId;
    }

    public function integrityCheck(): bool
    {
        DB::statement("INSERT INTO catalog_title_search_fts(catalog_title_search_fts, rank) VALUES('integrity-check', 1)");

        return true;
    }

    /**
     * @param  Collection<int, int>  $ids
     * @param  array{requested: int, indexed: int, unchanged: int, deleted: int}  $result
     */
    private function indexChunk(Collection $ids, array &$result): void
    {
        $titles = $this->titles->visibleTo(null)
            ->select([
                'catalog_titles.id',
                'catalog_titles.title',
                'catalog_titles.original_title',
                'catalog_titles.description',
            ])
            ->whereKey($ids)
            ->with([
                'aliases:id,catalog_title_id,name',
                ...$this->taxonomies->relationSummaryLoads(),
            ])
            ->get();
        $visibleIds = $titles->modelKeys();
        $missingIds = $ids->diff($visibleIds);

        if ($missingIds->isNotEmpty()) {
            $result['deleted'] += CatalogTitleSearchDocument::query()
                ->whereKey($missingIds)
                ->delete();
        }

        if ($titles->isEmpty()) {
            return;
        }

        $fingerprints = CatalogTitleSearchDocument::query()
            ->whereKey($visibleIds)
            ->pluck('fingerprint', 'catalog_title_id');
        $timestamp = now();
        $rows = [];

        foreach ($titles as $title) {
            $document = $this->documents->build($title);

            if ($fingerprints->get($title->id) === $document['fingerprint']) {
                $result['unchanged']++;

                continue;
            }

            $rows[] = [
                ...$document,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($rows === []) {
            return;
        }

        CatalogTitleSearchDocument::query()->upsert(
            $rows,
            ['catalog_title_id'],
            [
                'title',
                'original_title',
                'aliases',
                'transliteration',
                'people',
                'taxonomies',
                'description',
                'suggestion_names',
                'normalized_title_key',
                'normalized_original_title_key',
                'normalized_alias_keys',
                'fingerprint',
                'updated_at',
            ],
        );
        $result['indexed'] += count($rows);
    }
}
