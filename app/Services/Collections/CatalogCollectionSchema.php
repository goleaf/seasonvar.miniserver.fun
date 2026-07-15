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
}
