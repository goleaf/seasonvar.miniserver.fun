<?php

declare(strict_types=1);

namespace App\Services\Collections;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class CatalogCollectionSchema
{
    private const REQUIRED_TABLES = [
        'catalog_collections',
        'catalog_collection_slugs',
        'catalog_collection_items',
        'catalog_collection_reports',
        'catalog_collection_translations',
    ];

    private ?bool $available = null;

    private ?bool $sourceSyncAvailable = null;

    public function available(): bool
    {
        $configured = config('catalog-collections.schema_available');

        if (is_bool($configured)) {
            return $configured;
        }

        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $tables = Schema::getTableListing(schemaQualified: false);

            return $this->available = array_diff(self::REQUIRED_TABLES, $tables) === []
                && Schema::hasColumn('users', 'public_id');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    public function sourceSyncAvailable(): bool
    {
        $configured = config('catalog-collections.source_sync_schema_available');

        if (is_bool($configured)) {
            return $configured;
        }

        if ($this->sourceSyncAvailable !== null) {
            return $this->sourceSyncAvailable;
        }

        try {
            return $this->sourceSyncAvailable = Schema::hasTable('catalog_collection_sync_runs')
                && Schema::hasTable('catalog_collection_sources');
        } catch (Throwable) {
            return $this->sourceSyncAvailable = false;
        }
    }
}
