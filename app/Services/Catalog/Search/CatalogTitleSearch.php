<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogTitleSearch
{
    private ?bool $ready = null;

    public function candidateQuery(CatalogSearchQuery $search): ?Builder
    {
        if (! $this->isReady() || $search->ftsExpression === '') {
            return null;
        }

        return DB::table('catalog_title_search_fts')
            ->join(
                'catalog_title_search_documents',
                'catalog_title_search_documents.catalog_title_id',
                '=',
                'catalog_title_search_fts.rowid',
            )
            ->whereRaw('catalog_title_search_fts MATCH ?', [$search->ftsExpression])
            ->select('catalog_title_search_documents.catalog_title_id')
            ->selectRaw(
                'CASE WHEN catalog_title_search_documents.normalized_title_key = ? THEN 0 ELSE 1 END AS exact_title_rank',
                [$search->normalized],
            )
            ->selectRaw(
                'CASE WHEN catalog_title_search_documents.normalized_original_title_key = ? THEN 0 ELSE 1 END AS exact_original_title_rank',
                [$search->normalized],
            )
            ->selectRaw(
                'CASE WHEN instr(char(10) || catalog_title_search_documents.normalized_alias_keys || char(10), char(10) || ? || char(10)) > 0 THEN 0 ELSE 1 END AS exact_alias_rank',
                [$search->normalized],
            )
            ->selectRaw(
                'bm25(catalog_title_search_fts, 12.0, 10.0, 9.0, 7.0, 6.0, 4.5, 1.0) AS bm25_score',
            )
            ->orderBy('exact_title_rank')
            ->orderBy('exact_original_title_rank')
            ->orderBy('exact_alias_rank')
            ->orderBy('bm25_score')
            ->orderByDesc('catalog_title_search_documents.catalog_title_id');
    }

    public function isReady(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        if (! Schema::hasTable('catalog_title_search_fts') || ! Schema::hasTable('catalog_search_index_states')) {
            return $this->ready = false;
        }

        $state = CatalogSearchIndexState::query()->find(CatalogSearchIndexState::SINGLETON_ID);

        return $this->ready = $state !== null
            && $state->status === CatalogSearchIndexStatus::Ready
            && $state->version === CatalogSearchIndexer::INDEX_VERSION
            && $state->source_count === $state->document_count
            && $state->completed_at !== null;
    }

    public function forgetState(): void
    {
        $this->ready = null;
    }
}
