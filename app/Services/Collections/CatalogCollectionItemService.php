<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionItemService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly CatalogCollectionRateLimiter $rateLimiter,
    ) {}

    public function add(User $actor, CatalogCollection $collection, CatalogTitle $title): bool
    {
        Gate::forUser($actor)->authorize('manageItems', $collection);
        Gate::forUser($actor)->authorize('interact', $title);
        $this->rateLimiter->ensure($actor, 'membership');

        $created = DB::transaction(function () use ($actor, $collection, $title): bool {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('manageItems', $locked);
            $currentTitle = CatalogTitle::query()->lockForUpdate()->findOrFail($title->id);
            Gate::forUser($actor)->authorize('interact', $currentTitle);
            $existing = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->whereBelongsTo($currentTitle)
                ->exists();

            if ($existing) {
                return false;
            }

            $summary = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->selectRaw('COUNT(*) as aggregate, COALESCE(MAX(position), 0) as maximum_position')
                ->first();
            $count = (int) ($summary->aggregate ?? 0);

            if ($count >= max(1, (int) config('catalog-collections.maximum_items_per_collection', 5_000))) {
                throw ValidationException::withMessages(['collection' => [__('collections.errors.item_limit')]]);
            }

            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $locked->id,
                'catalog_title_id' => $currentTitle->id,
                'added_by_id' => $actor->id,
                'position' => (int) ($summary->maximum_position ?? 0) + 1,
            ]);
            $this->touch($locked);

            return true;
        }, attempts: 3);

        $this->cache->changed($collection);

        return $created;
    }

    public function remove(User $actor, CatalogCollection $collection, CatalogTitle|int $title): bool
    {
        Gate::forUser($actor)->authorize('manageItems', $collection);
        $this->rateLimiter->ensure($actor, 'membership');
        $titleId = $title instanceof CatalogTitle ? $title->id : $title;

        $removed = DB::transaction(function () use ($actor, $collection, $titleId): bool {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('manageItems', $locked);
            $deleted = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->where('catalog_title_id', $titleId)
                ->delete();

            if ($deleted === 0) {
                return false;
            }

            $this->normalizePositions($locked);
            $this->touch($locked);

            return true;
        }, attempts: 3);

        $this->cache->changed($collection);

        return $removed;
    }

    /**
     * @param  list<mixed>  $selectedCollectionPublicIds
     * @return list<int>
     */
    public function synchronizeMembership(User $actor, CatalogTitle $title, array $selectedCollectionPublicIds): array
    {
        Gate::forUser($actor)->authorize('interact', $title);
        $this->rateLimiter->ensure($actor, 'membership');

        if (count($selectedCollectionPublicIds) > 100) {
            abort(404);
        }

        $normalizedPublicIds = [];

        foreach ($selectedCollectionPublicIds as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId)) {
                abort(404);
            }

            $normalizedPublicIds[] = Str::lower($publicId);
        }

        $selectedCollectionPublicIds = array_values(array_unique($normalizedPublicIds));

        [$manageable, $changed] = DB::transaction(function () use ($actor, $title, $selectedCollectionPublicIds): array {
            $manageable = CatalogCollection::query()
                ->where('owner_id', $actor->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $manageable->each(fn (CatalogCollection $collection) => Gate::forUser($actor)->authorize('manageItems', $collection));
            $currentTitle = CatalogTitle::query()->lockForUpdate()->findOrFail($title->id);
            Gate::forUser($actor)->authorize('interact', $currentTitle);
            $manageablePublicIds = $manageable->pluck('public_id')->all();
            $manageablePublicIdsById = $manageable->pluck('public_id', 'id');

            if (array_diff($selectedCollectionPublicIds, $manageablePublicIds) !== []) {
                abort(404);
            }

            $selected = array_fill_keys($selectedCollectionPublicIds, true);
            $maximumItems = max(1, (int) config('catalog-collections.maximum_items_per_collection', 5_000));
            $existingItems = CatalogCollectionItem::query()
                ->whereIn('catalog_collection_id', $manageable->modelKeys())
                ->where('catalog_title_id', $currentTitle->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('catalog_collection_id');
            $additions = $manageable->filter(fn (CatalogCollection $collection): bool => isset($selected[$collection->public_id])
                && ! $existingItems->has($collection->id));
            $removals = $existingItems->filter(fn (CatalogCollectionItem $item): bool => ! isset(
                $selected[(string) ($manageablePublicIdsById->get($item->catalog_collection_id) ?? '')],
            ));
            $summaries = CatalogCollectionItem::query()
                ->whereIn('catalog_collection_id', $additions->modelKeys())
                ->selectRaw('catalog_collection_id, COUNT(*) as aggregate, COALESCE(MAX(position), 0) as maximum_position')
                ->groupBy('catalog_collection_id')
                ->get()
                ->keyBy('catalog_collection_id');

            foreach ($manageable as $collection) {
                if ($additions->contains('id', $collection->id)
                    && (int) ($summaries->get($collection->id)->aggregate ?? 0) >= $maximumItems) {
                    throw ValidationException::withMessages(['collection' => [__('collections.errors.item_limit')]]);
                }
            }

            $timestamp = now();
            if ($additions->isNotEmpty()) {
                CatalogCollectionItem::query()->insert($additions
                    ->map(fn (CatalogCollection $collection): array => [
                        'catalog_collection_id' => $collection->id,
                        'catalog_title_id' => $currentTitle->id,
                        'added_by_id' => $actor->id,
                        'position' => (int) ($summaries->get($collection->id)->maximum_position ?? 0) + 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all());
            }

            if ($removals->isNotEmpty()) {
                CatalogCollectionItem::query()->whereKey($removals->modelKeys())->delete();
            }

            $changedIds = $additions->modelKeys();

            foreach ($removals->pluck('catalog_collection_id')->unique() as $collectionId) {
                $collection = $manageable->firstWhere('id', $collectionId);

                if ($collection instanceof CatalogCollection) {
                    $this->normalizePositions($collection);
                    $changedIds[] = $collection->id;
                }
            }

            $changedIds = array_values(array_unique($changedIds));

            foreach ($manageable->whereIn('id', $changedIds) as $collection) {
                $this->touch($collection);
            }

            return [$manageable, $changedIds];
        }, attempts: 3);

        $this->cache->changedMany($changed === [] ? $manageable : $manageable->whereIn('id', $changed));

        return $changed;
    }

    /** @param list<mixed> $orderedItemIds */
    public function reorder(User $actor, CatalogCollection $collection, array $orderedItemIds): void
    {
        Gate::forUser($actor)->authorize('manageItems', $collection);
        $this->rateLimiter->ensure($actor, 'reorder', 'order');
        $limit = max(1, (int) config('catalog-collections.maximum_reorder_items', 500));
        if ($orderedItemIds === [] || count($orderedItemIds) > $limit) {
            throw ValidationException::withMessages(['order' => [__('collections.errors.invalid_order')]]);
        }

        $normalizedItemIds = [];

        foreach ($orderedItemIds as $itemId) {
            if (! is_int($itemId) && (! is_string($itemId) || ! ctype_digit($itemId))) {
                throw ValidationException::withMessages(['order' => [__('collections.errors.invalid_order')]]);
            }

            $itemId = (int) $itemId;

            if ($itemId <= 0) {
                throw ValidationException::withMessages(['order' => [__('collections.errors.invalid_order')]]);
            }

            $normalizedItemIds[] = $itemId;
        }

        if (count(array_unique($normalizedItemIds)) !== count($normalizedItemIds)) {
            throw ValidationException::withMessages(['order' => [__('collections.errors.invalid_order')]]);
        }

        $orderedItemIds = $normalizedItemIds;

        DB::transaction(function () use ($actor, $collection, $orderedItemIds): void {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('manageItems', $locked);
            $allIds = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (array_diff($orderedItemIds, $allIds) !== []) {
                throw ValidationException::withMessages(['order' => [__('collections.errors.invalid_order')]]);
            }

            $completeOrder = [...$orderedItemIds, ...array_values(array_diff($allIds, $orderedItemIds))];

            if ($completeOrder === $allIds) {
                return;
            }

            foreach ($completeOrder as $index => $itemId) {
                if (($allIds[$index] ?? null) !== $itemId) {
                    CatalogCollectionItem::query()->whereKey($itemId)->update(['position' => $index + 1]);
                }
            }

            $this->touch($locked);
        }, attempts: 3);

        $this->cache->changed($collection);
    }

    public function move(User $actor, CatalogCollection $collection, int $itemId, int $direction): bool
    {
        Gate::forUser($actor)->authorize('manageItems', $collection);
        $this->rateLimiter->ensure($actor, 'reorder', 'order');
        abort_unless(in_array($direction, [-1, 1], true), 404);
        $changed = DB::transaction(function () use ($actor, $collection, $itemId, $direction): bool {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('manageItems', $locked);
            $item = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->lockForUpdate()
                ->findOrFail($itemId);
            $neighbor = CatalogCollectionItem::query()
                ->whereBelongsTo($locked, 'collection')
                ->where(function ($query) use ($item, $direction): void {
                    $operator = $direction < 0 ? '<' : '>';
                    $query->where('position', $operator, $item->position)
                        ->orWhere(function ($query) use ($item, $operator): void {
                            $query->where('position', $item->position)
                                ->where('id', $operator, $item->id);
                        });
                })
                ->when(
                    $direction < 0,
                    fn ($query) => $query->orderByDesc('position')->orderByDesc('id'),
                    fn ($query) => $query->orderBy('position')->orderBy('id'),
                )
                ->lockForUpdate()
                ->first();

            if ($neighbor === null) {
                return false;
            }

            $itemPosition = $item->position;
            $item->position = $neighbor->position;
            $neighbor->position = $itemPosition;

            if ($item->position === $neighbor->position) {
                $this->normalizePositions($locked);
            } else {
                $item->save();
                $neighbor->save();
            }

            $this->touch($locked);

            return true;
        }, attempts: 3);

        $this->cache->changed($collection);

        return $changed;
    }

    public function mergeTitle(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        if (! Schema::hasTable('catalog_collection_items')) {
            return;
        }

        $changed = false;

        CatalogCollectionItem::query()
            ->where('catalog_title_id', $duplicate->id)
            ->lockForUpdate()
            ->eachById(function (CatalogCollectionItem $item) use ($canonical, &$changed): void {
                $collection = CatalogCollection::query()->withTrashed()->lockForUpdate()->find($item->catalog_collection_id);
                $existing = CatalogCollectionItem::query()
                    ->where('catalog_collection_id', $item->catalog_collection_id)
                    ->where('catalog_title_id', $canonical->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'position' => min($existing->position, $item->position),
                        'created_at' => $existing->created_at?->lte($item->created_at) ? $existing->created_at : $item->created_at,
                        'added_by_id' => $existing->added_by_id ?? $item->added_by_id,
                    ])->save();
                    $item->delete();
                } else {
                    $item->catalog_title_id = $canonical->id;
                    $item->save();
                }

                if ($collection !== null) {
                    $this->normalizePositions($collection);
                    $this->touch($collection);
                    $changed = true;
                }
            }, 500);

        if ($changed) {
            $this->cache->changed();
        }
    }

    private function normalizePositions(CatalogCollection $collection): void
    {
        CatalogCollectionItem::query()
            ->whereBelongsTo($collection, 'collection')
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (mixed $id, int $index) => CatalogCollectionItem::query()->whereKey($id)->update(['position' => $index + 1]));
    }

    private function touch(CatalogCollection $collection): void
    {
        $collection->forceFill([
            'content_version' => $collection->content_version + 1,
            'updated_at' => now(),
        ])->save();
    }
}
