<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\DTOs\CatalogCollectionSourceMatch;
use App\DTOs\HdRezkaCollectionDefinition;
use App\DTOs\HdRezkaCollectionItemData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSourceItem;
use App\Models\CatalogCollectionSyncRun;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Services\Collections\CatalogCollectionSlugService;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final readonly class HdRezkaCollectionReconciler
{
    public function __construct(
        private CatalogCollectionSlugService $slugs,
        private CatalogCollectionCacheInvalidator $cache,
    ) {}

    /**
     * @param  list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch}>  $items
     * @return array{collection_id: int, created: bool, membership_changed: bool, matched: int, ambiguous: int, unmatched: int, removed: int}
     */
    public function reconcile(
        CatalogCollectionSyncRun $run,
        HdRezkaCollectionDefinition $definition,
        array $items,
        bool $complete,
    ): array {
        $name = UserPlainText::name($definition->name);

        if ($run->getKey() === null || $run->provider === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Некорректные данные reconciliation коллекции.');
        }

        $resolvedItems = $this->normalizeItems($items);
        $result = DB::transaction(function () use ($run, $definition, $name, $resolvedItems, $complete): array {
            $source = CatalogCollectionSource::query()
                ->where('provider', $run->provider)
                ->where('source_key', $definition->sourceKey)
                ->lockForUpdate()
                ->first();
            $created = false;
            $collection = $source?->catalog_collection_id !== null
                ? CatalogCollection::query()->withTrashed()->lockForUpdate()->find($source->catalog_collection_id)
                : null;

            if (! $collection instanceof CatalogCollection) {
                $created = true;
                $collection = $this->createCollection($name, publiclyListed: ! $source instanceof CatalogCollectionSource);
            }

            if (! $source instanceof CatalogCollectionSource) {
                $source = new CatalogCollectionSource([
                    'provider' => $run->provider,
                    'source_key' => $definition->sourceKey,
                ]);
            }

            $source->catalog_collection_id = $collection->id;
            $source->source_path = $definition->path;
            $source->remote_name = $name;
            $source->cover_source_path = $definition->coverPath;
            $source->semantic_content_hash = $this->semanticHash($resolvedItems);
            $source->last_seen_run_id = $run->id;

            if ($complete) {
                $source->retry_count = 0;
                $source->last_retry_at = null;
                $source->last_successful_sync_at = now();
            } else {
                $source->retry_count = (int) $source->retry_count + 1;
                $source->last_retry_at = now();
            }

            $source->save();
            $this->upsertSourceItems($source, $run, $resolvedItems);

            $existingMembership = $collection->items()
                ->get(['catalog_title_id', 'position'])
                ->mapWithKeys(fn (CatalogCollectionItem $item): array => [
                    (int) $item->catalog_title_id => (int) $item->position,
                ])
                ->all();
            $desiredMembership = $this->desiredMembership($resolvedItems, $existingMembership, $complete);
            $membershipChanged = $existingMembership !== $desiredMembership;
            $removed = $complete ? count(array_diff(array_keys($existingMembership), array_keys($desiredMembership))) : 0;

            if ($membershipChanged) {
                $this->writeMembership($collection, $desiredMembership, $complete);
            }

            $nameChanged = $collection->name !== $name;

            if ($nameChanged) {
                $this->slugs->change($collection, $name);
                $collection->name = $name;
            }

            if (! $created && ($nameChanged || $membershipChanged)) {
                $collection->content_version = (int) $collection->content_version + 1;
            }

            if ($nameChanged || (! $created && $membershipChanged)) {
                $collection->save();
            }

            $counts = collect($resolvedItems)->countBy(
                fn (array $resolved): string => $resolved['match']->status->value,
            );

            return [
                'collection_id' => (int) $collection->id,
                'created' => $created,
                'membership_changed' => $membershipChanged,
                'matched' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Matched->value, 0),
                'ambiguous' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Ambiguous->value, 0),
                'unmatched' => (int) $counts->get(CatalogCollectionSourceMatchStatus::Unmatched->value, 0),
                'removed' => $removed,
                'public_changed' => $created || $nameChanged || $membershipChanged,
            ];
        }, attempts: 3);

        if ($result['public_changed']) {
            $this->cache->changed(CatalogCollection::query()->withTrashed()->find($result['collection_id']));
        }

        unset($result['public_changed']);

        /** @var array{collection_id: int, created: bool, membership_changed: bool, matched: int, ambiguous: int, unmatched: int, removed: int} $result */
        return $result;
    }

    private function createCollection(string $name, bool $publiclyListed): CatalogCollection
    {
        $publicId = (string) Str::uuid();

        return CatalogCollection::query()->create([
            'public_id' => $publicId,
            'owner_id' => null,
            'name' => $name,
            'description' => null,
            'slug' => $this->slugs->generate($name, $publicId),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => $publiclyListed
                ? CatalogCollectionVisibility::Public
                : CatalogCollectionVisibility::Private,
            'moderation_status' => $publiclyListed
                ? CatalogCollectionModerationStatus::Approved
                : CatalogCollectionModerationStatus::Archived,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'cover_version' => 0,
            'content_version' => 1,
            'published_at' => $publiclyListed ? now() : null,
        ]);
    }

    /**
     * @param  list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch}>  $items
     * @return list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch, source_position: int}>
     */
    private function normalizeItems(array $items): array
    {
        $resolved = [];
        $seen = [];

        foreach ($items as $value) {
            $item = $value['item'] ?? null;
            $match = $value['match'] ?? null;

            if (! $item instanceof HdRezkaCollectionItemData || ! $match instanceof CatalogCollectionSourceMatch) {
                throw new InvalidArgumentException('Некорректная source item запись коллекции.');
            }

            if ($item->sourceItemKey === '' || isset($seen[$item->sourceItemKey])) {
                continue;
            }

            if ($match->status === CatalogCollectionSourceMatchStatus::Matched && $match->catalogTitleId === null) {
                throw new InvalidArgumentException('Matched source item не содержит локальный catalog title ID.');
            }

            $seen[$item->sourceItemKey] = true;
            $resolved[] = [
                'item' => $item,
                'match' => $match,
                'source_position' => count($resolved) + 1,
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch, source_position: int}>  $items
     */
    private function upsertSourceItems(
        CatalogCollectionSource $source,
        CatalogCollectionSyncRun $run,
        array $items,
    ): void {
        if ($items === []) {
            return;
        }

        $timestamp = now();
        $rows = collect($items)->map(function (array $resolved) use ($source, $run, $timestamp): array {
            $item = $resolved['item'];
            $match = $resolved['match'];

            return [
                'catalog_collection_source_id' => $source->id,
                'source_item_key' => $item->sourceItemKey,
                'source_title' => $item->title,
                'normalized_title_key' => $item->normalizedTitleKey,
                'normalized_title_hash' => hash('sha256', $item->normalizedTitleKey),
                'source_year' => $item->year,
                'source_type' => $item->type,
                'countries' => json_encode($item->countries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'detail_path' => $item->detailPath,
                'detail_path_hash' => hash('sha256', $item->detailPath),
                'source_page' => $item->page,
                'source_position' => $resolved['source_position'],
                'match_status' => $match->status->value,
                'catalog_title_id' => $match->catalogTitleId,
                'match_method' => $match->method,
                'match_confidence' => $match->confidence,
                'match_reasons' => json_encode($match->reasons, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'last_seen_run_id' => $run->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        })->all();

        CatalogCollectionSourceItem::query()->upsert(
            $rows,
            ['catalog_collection_source_id', 'source_item_key'],
            [
                'source_title',
                'normalized_title_key',
                'normalized_title_hash',
                'source_year',
                'source_type',
                'countries',
                'detail_path',
                'detail_path_hash',
                'source_page',
                'source_position',
                'match_status',
                'catalog_title_id',
                'match_method',
                'match_confidence',
                'match_reasons',
                'last_seen_run_id',
                'updated_at',
            ],
        );
    }

    /**
     * @param  list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch, source_position: int}>  $items
     * @param  array<int, int>  $existing
     * @return array<int, int>
     */
    private function desiredMembership(array $items, array $existing, bool $complete): array
    {
        $orderedIds = [];

        foreach ($items as $resolved) {
            $match = $resolved['match'];

            if ($match->status === CatalogCollectionSourceMatchStatus::Matched
                && $match->catalogTitleId !== null
                && ! in_array($match->catalogTitleId, $orderedIds, true)) {
                $orderedIds[] = $match->catalogTitleId;
            }
        }

        if (! $complete) {
            foreach (array_keys($existing) as $catalogTitleId) {
                if (! in_array($catalogTitleId, $orderedIds, true)) {
                    $orderedIds[] = $catalogTitleId;
                }
            }
        }

        $desired = [];

        foreach ($orderedIds as $index => $catalogTitleId) {
            $desired[(int) $catalogTitleId] = $index + 1;
        }

        return $desired;
    }

    /** @param array<int, int> $membership */
    private function writeMembership(CatalogCollection $collection, array $membership, bool $complete): void
    {
        $timestamp = now();

        if ($membership !== []) {
            $rows = [];

            foreach ($membership as $catalogTitleId => $position) {
                $rows[] = [
                    'catalog_collection_id' => $collection->id,
                    'catalog_title_id' => $catalogTitleId,
                    'added_by_id' => null,
                    'position' => $position,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            CatalogCollectionItem::query()->upsert(
                $rows,
                ['catalog_collection_id', 'catalog_title_id'],
                ['position', 'updated_at'],
            );
        }

        if (! $complete) {
            return;
        }

        $stale = CatalogCollectionItem::query()->where('catalog_collection_id', $collection->id);

        if ($membership !== []) {
            $stale->whereNotIn('catalog_title_id', array_keys($membership));
        }

        $stale->delete();
    }

    /**
     * @param  list<array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch, source_position: int}>  $items
     */
    private function semanticHash(array $items): string
    {
        try {
            $payload = collect($items)->map(fn (array $resolved): array => [
                $resolved['item']->sourceItemKey,
                $resolved['item']->normalizedTitleKey,
                $resolved['item']->year,
                $resolved['item']->type,
                $resolved['item']->countries,
                $resolved['source_position'],
            ])->all();

            return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Source items коллекции не сериализуются.', 0, $exception);
        }
    }
}
