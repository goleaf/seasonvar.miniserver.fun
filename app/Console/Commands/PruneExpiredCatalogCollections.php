<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogCollection;
use App\Services\Collections\CatalogCollectionCoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PruneExpiredCatalogCollections extends Command
{
    protected $signature = 'catalog-collections:prune {--limit= : Maximum collections to prune in one run}';

    protected $description = 'Permanently prune catalog collections after their documented restoration window';

    public function handle(CatalogCollectionCoverService $covers): int
    {
        if (! Schema::hasTable('catalog_collections')) {
            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit)
            ? (int) $limit
            : (int) config('catalog-collections.prune_batch_size', 200);
        $limit = max(1, min(1_000, $limit));
        $cutoff = now()->subDays(max(1, (int) config('catalog-collections.restoration_days', 30)));
        $ids = CatalogCollection::query()
            ->onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $pruned = 0;

        foreach ($ids as $id) {
            $collection = CatalogCollection::query()
                ->withTrashed()
                ->whereKey((int) $id)
                ->where('deleted_at', '<=', $cutoff)
                ->first();

            if (! $collection instanceof CatalogCollection) {
                continue;
            }

            $covers->deleteWithCollection($collection);
            $pruned++;
        }

        $this->components->info("Pruned {$pruned} expired catalog collections.");

        return self::SUCCESS;
    }
}
