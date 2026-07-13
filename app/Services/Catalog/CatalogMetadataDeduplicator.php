<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Taxonomy;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class CatalogMetadataDeduplicator
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogRelationNameSanitizer $names,
        private readonly CatalogSearchIndexer $search,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{checked: int, records_removed: int, links_removed: int, records_merged: int, links_moved: int, duplicate_links_removed: int, records_canonicalized: int, legacy_records_removed: int, legacy_links_removed: int, affected_titles: int}
     */
    public function run(?callable $progress = null): array
    {
        $progress ??= static function (): void {};
        $chunkSize = $this->chunkSize();
        $result = $this->emptyResult();
        $affectedTitleIds = [];

        $progress('catalog-relations-cleanup-started', [
            'chunk_size' => $chunkSize,
        ]);

        foreach ($this->taxonomies->relations() as $type => $config) {
            $typeResult = $this->deduplicateRelation(
                $type,
                $config['model'],
                $config['relation'],
                $chunkSize,
                $affectedTitleIds,
            );

            foreach (array_keys($typeResult) as $key) {
                $result[$key] += $typeResult[$key];
            }

            if ($this->changed($typeResult)) {
                $progress('catalog-relations-cleanup-type-complete', [
                    'type' => $type,
                    ...$typeResult,
                ]);
            }
        }

        $legacyResult = $this->cleanupLegacyTaxonomies($chunkSize, $affectedTitleIds);
        $result['checked'] += $legacyResult['checked'];
        $result['legacy_records_removed'] = $legacyResult['records_removed'];
        $result['legacy_links_removed'] = $legacyResult['links_removed'];

        if ($legacyResult['records_removed'] > 0 || $legacyResult['links_removed'] > 0) {
            $progress('catalog-relations-legacy-cleanup-complete', $legacyResult);
        }

        $result['affected_titles'] = count($affectedTitleIds);
        $this->search->synchronizeTitleIds(array_keys($affectedTitleIds));
        $progress('catalog-relations-cleanup-complete', $result);

        return $result;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, true>  $affectedTitleIds
     * @return array{checked: int, records_removed: int, links_removed: int, records_merged: int, links_moved: int, duplicate_links_removed: int, records_canonicalized: int}
     */
    private function deduplicateRelation(
        string $type,
        string $modelClass,
        string $relationName,
        int $chunkSize,
        array &$affectedTitleIds,
    ): array {
        $result = $this->emptyTypeResult();
        $canonicalByKey = [];
        $duplicates = [];
        $invalidIds = [];

        foreach ($modelClass::query()->select(['id', 'name', 'slug', 'source_url'])->lazyById($chunkSize) as $record) {
            $result['checked']++;
            $recordId = (int) $record->getKey();
            $name = $this->names->normalize((string) $record->name);

            if (! $this->names->isValid($type, $name)) {
                $invalidIds[] = $recordId;

                continue;
            }

            $key = $this->names->canonicalKey($type, $name);

            if (! isset($canonicalByKey[$key])) {
                $canonicalByKey[$key] = [
                    'id' => $recordId,
                    'name' => $name,
                    'slug' => (string) $record->slug,
                    'source_url' => $record->source_url,
                ];

                continue;
            }

            $canonicalId = $canonicalByKey[$key]['id'];
            $duplicates[$recordId] = $canonicalId;
            $canonicalByKey[$key]['name'] = $this->names->preferredName(
                $type,
                $canonicalByKey[$key]['name'],
                $name,
            );
            $canonicalByKey[$key]['source_url'] ??= $record->source_url;
        }

        /** @var BelongsToMany<Model, CatalogTitle, Pivot, 'pivot'> $relation */
        $relation = (new CatalogTitle)->{$relationName}();
        $pivotTable = $relation->getTable();
        $titleKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();

        foreach (array_chunk($invalidIds, $chunkSize) as $ids) {
            DB::transaction(function () use ($modelClass, $pivotTable, $titleKey, $relatedKey, $ids, &$result, &$affectedTitleIds): void {
                $titleIds = DB::table($pivotTable)
                    ->whereIn($relatedKey, $ids)
                    ->pluck($titleKey)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($titleIds as $titleId) {
                    $affectedTitleIds[$titleId] = true;
                }

                $result['links_removed'] += DB::table($pivotTable)->whereIn($relatedKey, $ids)->delete();
                $result['records_removed'] += $modelClass::query()->whereKey($ids)->delete();
            });
        }

        foreach (array_chunk($duplicates, $chunkSize, true) as $duplicateMap) {
            DB::transaction(function () use ($modelClass, $pivotTable, $titleKey, $relatedKey, $duplicateMap, &$result, &$affectedTitleIds): void {
                $duplicateIds = array_keys($duplicateMap);
                $links = DB::table($pivotTable)
                    ->whereIn($relatedKey, $duplicateIds)
                    ->get([$titleKey, $relatedKey]);
                $targetLinks = [];

                foreach ($links as $link) {
                    $titleId = (int) $link->{$titleKey};
                    $targetLinks[$titleId.'|'.$duplicateMap[(int) $link->{$relatedKey}]] = [
                        $titleKey => $titleId,
                        $relatedKey => $duplicateMap[(int) $link->{$relatedKey}],
                    ];
                    $affectedTitleIds[$titleId] = true;
                }

                $inserted = $targetLinks === []
                    ? 0
                    : DB::table($pivotTable)->insertOrIgnore(array_values($targetLinks));

                $result['links_moved'] += $inserted;
                $result['duplicate_links_removed'] += $links->count() - $inserted;
                DB::table($pivotTable)->whereIn($relatedKey, $duplicateIds)->delete();
                $result['records_merged'] += $modelClass::query()->whereKey($duplicateIds)->delete();
            });
        }

        foreach ($canonicalByKey as $key => $canonical) {
            $changes = [];

            if ($canonical['name'] !== '' && $canonical['name'] !== null) {
                $changes['name'] = $canonical['name'];
            }

            if ($canonical['slug'] !== $key) {
                $changes['slug'] = $key;
            }

            if ($canonical['source_url'] !== null) {
                $changes['source_url'] = $canonical['source_url'];
            }

            if ($changes === []) {
                continue;
            }

            $changes['updated_at'] = now();
            $result['records_canonicalized'] += $modelClass::query()
                ->whereKey($canonical['id'])
                ->where(function ($query) use ($changes): void {
                    foreach (array_diff_key($changes, ['updated_at' => true]) as $column => $value) {
                        $query->orWhere($column, '!=', $value)->orWhereNull($column);
                    }
                })
                ->update($changes);
        }

        return $result;
    }

    /**
     * @param  array<int, true>  $affectedTitleIds
     * @return array{checked: int, records_removed: int, links_removed: int}
     */
    private function cleanupLegacyTaxonomies(int $chunkSize, array &$affectedTitleIds): array
    {
        $result = ['checked' => 0, 'records_removed' => 0, 'links_removed' => 0];
        $invalidIds = [];

        foreach (Taxonomy::query()
            ->select(['id', 'type', 'name'])
            ->whereIn('type', array_keys($this->taxonomies->relations()))
            ->lazyById($chunkSize) as $taxonomy) {
            $result['checked']++;

            if (! $this->names->isValid((string) $taxonomy->type, (string) $taxonomy->name)) {
                $invalidIds[] = (int) $taxonomy->getKey();
            }
        }

        foreach (array_chunk($invalidIds, $chunkSize) as $ids) {
            DB::transaction(function () use ($ids, &$result, &$affectedTitleIds): void {
                $titleIds = DB::table('catalog_title_taxonomy')
                    ->whereIn('taxonomy_id', $ids)
                    ->pluck('catalog_title_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($titleIds as $titleId) {
                    $affectedTitleIds[$titleId] = true;
                }

                $result['links_removed'] += DB::table('catalog_title_taxonomy')->whereIn('taxonomy_id', $ids)->delete();
                $result['records_removed'] += Taxonomy::query()->whereKey($ids)->delete();
            });
        }

        return $result;
    }

    /** @return array{checked: int, records_removed: int, links_removed: int, records_merged: int, links_moved: int, duplicate_links_removed: int, records_canonicalized: int, legacy_records_removed: int, legacy_links_removed: int, affected_titles: int} */
    private function emptyResult(): array
    {
        return [
            'checked' => 0,
            'records_removed' => 0,
            'links_removed' => 0,
            'records_merged' => 0,
            'links_moved' => 0,
            'duplicate_links_removed' => 0,
            'records_canonicalized' => 0,
            'legacy_records_removed' => 0,
            'legacy_links_removed' => 0,
            'affected_titles' => 0,
        ];
    }

    /** @return array{checked: int, records_removed: int, links_removed: int, records_merged: int, links_moved: int, duplicate_links_removed: int, records_canonicalized: int} */
    private function emptyTypeResult(): array
    {
        return [
            'checked' => 0,
            'records_removed' => 0,
            'links_removed' => 0,
            'records_merged' => 0,
            'links_moved' => 0,
            'duplicate_links_removed' => 0,
            'records_canonicalized' => 0,
        ];
    }

    /** @param array<string, int> $result */
    private function changed(array $result): bool
    {
        return $result['records_removed'] > 0
            || $result['links_removed'] > 0
            || $result['records_merged'] > 0
            || $result['links_moved'] > 0
            || $result['duplicate_links_removed'] > 0
            || $result['records_canonicalized'] > 0;
    }

    private function chunkSize(): int
    {
        return max(1, min(500, (int) config('seasonvar.import.chunk_size', 200)));
    }
}
