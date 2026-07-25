<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionCoverPurgeResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class CatalogCollectionCoverPurgeService
{
    public const string PREFIX = 'catalog-collections/';

    private const string DISK = 'uploads';

    private const int BATCH_SIZE = 200;

    public function __construct(
        private FilesystemManager $filesystems,
    ) {}

    public function run(bool $execute): CatalogCollectionCoverPurgeResult
    {
        try {
            $disk = $this->filesystems->disk(self::DISK);
            $targets = $this->targets($disk);
        } catch (Throwable) {
            return $this->failed($execute);
        }

        if (! $execute) {
            return new CatalogCollectionCoverPurgeResult(
                executed: false,
                files: count($targets['files']),
                bytes: $targets['bytes'],
                collectionRows: $targets['collection_rows'],
                sourceRows: $targets['source_rows'],
                failures: 0,
                readyForSchemaDrop: $this->isEmpty($targets),
            );
        }

        try {
            foreach (array_chunk($targets['files'], self::BATCH_SIZE) as $files) {
                if ($files !== [] && ! $disk->delete($files)) {
                    return $this->failedFromTargets($targets);
                }
            }

            if ($disk->directoryExists(self::PREFIX) && ! $disk->deleteDirectory(self::PREFIX)) {
                return $this->failedFromTargets($targets);
            }

            $this->clearCollectionMetadata();
            $this->clearSourceMetadata();

            $remaining = $this->targets($disk);
        } catch (Throwable) {
            return $this->failedFromTargets($targets);
        }

        return new CatalogCollectionCoverPurgeResult(
            executed: true,
            files: count($targets['files']),
            bytes: $targets['bytes'],
            collectionRows: $targets['collection_rows'],
            sourceRows: $targets['source_rows'],
            failures: $this->isEmpty($remaining) ? 0 : 1,
            readyForSchemaDrop: $this->isEmpty($remaining),
        );
    }

    /**
     * @return array{
     *     files: list<string>,
     *     bytes: int,
     *     collection_rows: int,
     *     source_rows: int
     * }
     */
    private function targets(FilesystemAdapter $disk): array
    {
        $files = array_values($disk->allFiles(self::PREFIX));
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += max(0, $disk->size($file));
        }

        return [
            'files' => $files,
            'bytes' => $bytes,
            'collection_rows' => $this->collectionMetadataQuery()?->count() ?? 0,
            'source_rows' => $this->sourceMetadataQuery()?->count() ?? 0,
        ];
    }

    private function clearCollectionMetadata(): void
    {
        $query = $this->collectionMetadataQuery();

        if ($query === null) {
            return;
        }

        $query->select('id')->chunkById(self::BATCH_SIZE, static function (Collection $rows): void {
            DB::table('catalog_collections')
                ->whereIn('id', $rows->pluck('id'))
                ->update([
                    'cover_disk' => null,
                    'cover_path' => null,
                    'cover_mime_type' => null,
                    'cover_size' => null,
                    'cover_version' => 0,
                ]);
        });
    }

    private function clearSourceMetadata(): void
    {
        $query = $this->sourceMetadataQuery();

        if ($query === null) {
            return;
        }

        $query->select('id')->chunkById(self::BATCH_SIZE, static function (Collection $rows): void {
            DB::table('catalog_collection_sources')
                ->whereIn('id', $rows->pluck('id'))
                ->update([
                    'cover_source_path' => null,
                    'cover_path' => null,
                    'cover_content_hash' => null,
                ]);
        });
    }

    private function collectionMetadataQuery(): ?Builder
    {
        $columns = ['cover_disk', 'cover_path', 'cover_mime_type', 'cover_size', 'cover_version'];

        if (! Schema::hasTable('catalog_collections') || ! Schema::hasColumns('catalog_collections', $columns)) {
            return null;
        }

        return DB::table('catalog_collections')->where(static function ($query): void {
            $query
                ->whereNotNull('cover_disk')
                ->orWhereNotNull('cover_path')
                ->orWhereNotNull('cover_mime_type')
                ->orWhereNotNull('cover_size')
                ->orWhere('cover_version', '>', 0);
        });
    }

    private function sourceMetadataQuery(): ?Builder
    {
        $columns = ['cover_source_path', 'cover_path', 'cover_content_hash'];

        if (! Schema::hasTable('catalog_collection_sources') || ! Schema::hasColumns('catalog_collection_sources', $columns)) {
            return null;
        }

        return DB::table('catalog_collection_sources')->where(static function ($query): void {
            $query
                ->whereNotNull('cover_source_path')
                ->orWhereNotNull('cover_path')
                ->orWhereNotNull('cover_content_hash');
        });
    }

    /** @param array{files: list<string>, bytes: int, collection_rows: int, source_rows: int} $targets */
    private function isEmpty(array $targets): bool
    {
        return $targets['files'] === []
            && $targets['bytes'] === 0
            && $targets['collection_rows'] === 0
            && $targets['source_rows'] === 0;
    }

    private function failed(bool $executed): CatalogCollectionCoverPurgeResult
    {
        return new CatalogCollectionCoverPurgeResult(
            executed: $executed,
            files: 0,
            bytes: 0,
            collectionRows: 0,
            sourceRows: 0,
            failures: 1,
            readyForSchemaDrop: false,
        );
    }

    /** @param array{files: list<string>, bytes: int, collection_rows: int, source_rows: int} $targets */
    private function failedFromTargets(array $targets): CatalogCollectionCoverPurgeResult
    {
        return new CatalogCollectionCoverPurgeResult(
            executed: true,
            files: count($targets['files']),
            bytes: $targets['bytes'],
            collectionRows: $targets['collection_rows'],
            sourceRows: $targets['source_rows'],
            failures: 1,
            readyForSchemaDrop: false,
        );
    }
}
