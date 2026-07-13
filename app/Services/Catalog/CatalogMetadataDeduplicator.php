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
    private const IDENTITY_TABLE = 'catalog_metadata_identity_map';

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

        try {
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
        } finally {
            $this->dropIdentityTable();
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
        /** @var BelongsToMany<Model, CatalogTitle, Pivot, 'pivot'> $relation */
        $relation = (new CatalogTitle)->{$relationName}();
        $pivotTable = $relation->getTable();
        $titleKey = $relation->getForeignPivotKeyName();
        $relatedKey = $relation->getRelatedPivotKeyName();
        $modelTable = (new $modelClass)->getTable();
        $this->resetIdentityTable();

        $modelClass::query()
            ->select(['id', 'name', 'source_url'])
            ->chunkById($chunkSize, function ($records) use ($type, $modelClass, $pivotTable, $titleKey, $relatedKey, &$result, &$affectedTitleIds): void {
                $identityRows = [];
                $invalidIds = [];
                $result['checked'] += $records->count();

                foreach ($records as $record) {
                    $recordId = (int) $record->getKey();
                    $name = $this->names->normalize((string) $record->name);

                    if (! $this->names->isValid($type, $name)) {
                        $invalidIds[] = $recordId;

                        continue;
                    }

                    $identityRows[] = [
                        'relation_id' => $recordId,
                        'canonical_key' => $this->names->canonicalKey($type, $name),
                        'normalized_name' => $name,
                        'source_url' => $record->source_url,
                    ];
                }

                DB::transaction(function () use ($identityRows, $invalidIds, $modelClass, $pivotTable, $titleKey, $relatedKey, &$result, &$affectedTitleIds): void {
                    if ($identityRows !== []) {
                        DB::table(self::IDENTITY_TABLE)->insert($identityRows);
                    }

                    if ($invalidIds === []) {
                        return;
                    }

                    $this->rememberAffectedTitles($pivotTable, $titleKey, $relatedKey, $invalidIds, $affectedTitleIds);
                    $result['links_removed'] += DB::table($pivotTable)->whereIn($relatedKey, $invalidIds)->delete();
                    $result['records_removed'] += $modelClass::query()->whereKey($invalidIds)->delete();
                });
            });

        $duplicateGroups = DB::table(self::IDENTITY_TABLE)
            ->selectRaw('canonical_key, MIN(relation_id) AS canonical_id, COUNT(*) AS records_count')
            ->groupBy('canonical_key')
            ->havingRaw('COUNT(*) > 1');

        foreach ($duplicateGroups->lazyById($chunkSize, 'canonical_key', 'canonical_key') as $group) {
            $identities = DB::table(self::IDENTITY_TABLE)
                ->where('canonical_key', $group->canonical_key)
                ->orderBy('relation_id')
                ->get();
            $canonicalId = (int) $group->canonical_id;
            $duplicateIds = $identities
                ->pluck('relation_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === $canonicalId)
                ->values()
                ->all();
            $preferredName = $identities->reduce(
                fn (?string $current, object $identity): string => $this->names->preferredName(
                    $type,
                    $current ?? '',
                    (string) $identity->normalized_name,
                ),
            );
            $sourceUrl = $identities->firstWhere('relation_id', $canonicalId)?->source_url
                ?? $identities->first(fn (object $identity): bool => $identity->source_url !== null)?->source_url;

            foreach (array_chunk($duplicateIds, $chunkSize) as $ids) {
                $duplicateMap = array_fill_keys($ids, $canonicalId);
                $this->mergeDuplicateRecords(
                    $modelClass,
                    $pivotTable,
                    $titleKey,
                    $relatedKey,
                    $duplicateMap,
                    $result,
                    $affectedTitleIds,
                );
            }

            DB::table(self::IDENTITY_TABLE)->whereIn('relation_id', $duplicateIds)->delete();
            DB::table(self::IDENTITY_TABLE)->where('relation_id', $canonicalId)->update([
                'normalized_name' => $preferredName,
                'source_url' => $sourceUrl,
            ]);
        }

        DB::table($modelTable.' as records')
            ->join(self::IDENTITY_TABLE.' as identities', 'identities.relation_id', '=', 'records.id')
            ->whereColumn('records.slug', '!=', 'identities.canonical_key')
            ->select('records.id as record_id')
            ->chunkById($chunkSize, function ($records) use ($type, $modelClass): void {
                DB::transaction(function () use ($records, $type, $modelClass): void {
                    foreach ($records as $record) {
                        $recordId = (int) $record->record_id;
                        $modelClass::query()->whereKey($recordId)->update([
                            'slug' => $this->stagingSlug($modelClass, $type, $recordId),
                            'updated_at' => now(),
                        ]);
                    }
                });
            }, 'records.id', 'record_id');

        DB::table($modelTable.' as records')
            ->join(self::IDENTITY_TABLE.' as identities', 'identities.relation_id', '=', 'records.id')
            ->where(function ($query): void {
                $query->whereColumn('records.name', '!=', 'identities.normalized_name')
                    ->orWhereColumn('records.slug', '!=', 'identities.canonical_key')
                    ->orWhere(function ($query): void {
                        $query->whereNull('records.source_url')->whereNotNull('identities.source_url');
                    });
            })
            ->select([
                'records.id as record_id',
                'records.source_url as current_source_url',
                'identities.canonical_key',
                'identities.normalized_name',
                'identities.source_url as identity_source_url',
            ])
            ->chunkById($chunkSize, function ($records) use ($modelClass, $pivotTable, $titleKey, $relatedKey, &$result, &$affectedTitleIds): void {
                $recordIds = $records->pluck('record_id')->map(fn (mixed $id): int => (int) $id)->all();
                $this->rememberAffectedTitles($pivotTable, $titleKey, $relatedKey, $recordIds, $affectedTitleIds);

                foreach ($records as $record) {
                    $changes = [
                        'name' => $record->normalized_name,
                        'slug' => $record->canonical_key,
                        'updated_at' => now(),
                    ];

                    if ($record->current_source_url === null && $record->identity_source_url !== null) {
                        $changes['source_url'] = $record->identity_source_url;
                    }

                    $result['records_canonicalized'] += $modelClass::query()
                        ->whereKey((int) $record->record_id)
                        ->update($changes);
                }
            }, 'records.id', 'record_id');

        return $result;
    }

    /** @param class-string<Model> $modelClass */
    private function stagingSlug(string $modelClass, string $type, int $recordId): string
    {
        $base = "catalog-dedup-stage-{$type}-{$recordId}";
        $candidate = $base;
        $suffix = 1;

        while ($modelClass::query()->where('slug', $candidate)->whereKeyNot($recordId)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, int>  $duplicateMap
     * @param  array<string, int>  $result
     * @param  array<int, true>  $affectedTitleIds
     */
    private function mergeDuplicateRecords(
        string $modelClass,
        string $pivotTable,
        string $titleKey,
        string $relatedKey,
        array $duplicateMap,
        array &$result,
        array &$affectedTitleIds,
    ): void {
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

    /** @param array<int, true> $affectedTitleIds */
    private function rememberAffectedTitles(
        string $pivotTable,
        string $titleKey,
        string $relatedKey,
        array $relationIds,
        array &$affectedTitleIds,
    ): void {
        DB::table($pivotTable)
            ->whereIn($relatedKey, $relationIds)
            ->pluck($titleKey)
            ->each(function (mixed $id) use (&$affectedTitleIds): void {
                $affectedTitleIds[(int) $id] = true;
            });
    }

    private function resetIdentityTable(): void
    {
        $this->dropIdentityTable();
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE catalog_metadata_identity_map (
                relation_id INTEGER PRIMARY KEY,
                canonical_key TEXT NOT NULL,
                normalized_name TEXT NOT NULL,
                source_url TEXT NULL
            )
            SQL);
        DB::statement('CREATE INDEX catalog_metadata_identity_key_idx ON catalog_metadata_identity_map (canonical_key, relation_id)');
    }

    private function dropIdentityTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS temp.catalog_metadata_identity_map');
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
