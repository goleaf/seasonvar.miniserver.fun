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
        'catalog_collection_categories',
        'catalog_collection_category_translations',
    ];

    private ?bool $available = null;

    private ?bool $sourceSyncAvailable = null;

    private ?bool $qualityAvailable = null;

    /** @var list<string>|null */
    private ?array $tables = null;

    private bool $tablesResolved = false;

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
            $tables = $this->tables();

            return $this->available = $tables !== null
                && array_diff(self::REQUIRED_TABLES, $tables) === []
                && Schema::hasColumn('users', 'public_id')
                && Schema::hasColumns('catalog_collections', [
                    'mode',
                    'smart_rules',
                    'smart_rules_version',
                ]);
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
            $tables = $this->tables();

            return $this->sourceSyncAvailable = $tables !== null
                && array_diff([
                    'catalog_collection_sync_runs',
                    'catalog_collection_sources',
                ], $tables) === [];
        } catch (Throwable) {
            return $this->sourceSyncAvailable = false;
        }
    }

    public function qualityAvailable(): bool
    {
        if ($this->qualityAvailable !== null) {
            return $this->qualityAvailable;
        }

        try {
            $tables = $this->tables();

            // The issues table is created last by the additive migration and
            // dropped first on rollback, so it is the rolling-deploy marker.
            return $this->qualityAvailable = $tables !== null
                && in_array('catalog_collection_quality_issues', $tables, true);
        } catch (Throwable) {
            return $this->qualityAvailable = false;
        }
    }

    /** @return list<string>|null */
    private function tables(): ?array
    {
        if ($this->tablesResolved) {
            return $this->tables;
        }

        $this->tablesResolved = true;

        try {
            return $this->tables = Schema::getTableListing(schemaQualified: false);
        } catch (Throwable) {
            return $this->tables = null;
        }
    }
}
