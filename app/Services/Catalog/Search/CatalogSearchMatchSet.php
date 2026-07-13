<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class CatalogSearchMatchSet
{
    private string $encodedIds;

    /** @param iterable<int, int> $ids */
    public function __construct(iterable $ids)
    {
        $normalizedIds = collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->encodedIds = json_encode($normalizedIds, JSON_THROW_ON_ERROR);
    }

    public function idsQuery(): Builder
    {
        return DB::query()
            ->fromRaw('json_each(?) as catalog_search_match_set', [$this->encodedIds])
            ->selectRaw('cast(catalog_search_match_set.value as integer) as catalog_title_id');
    }
}
