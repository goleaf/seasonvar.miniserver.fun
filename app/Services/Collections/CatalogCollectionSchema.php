<?php

declare(strict_types=1);

namespace App\Services\Collections;

use Illuminate\Support\Facades\Schema;

final class CatalogCollectionSchema
{
    private const REQUIRED_TABLES = [
        'catalog_collections',
        'catalog_collection_items',
        'catalog_collection_translations',
    ];

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $tables = Schema::getTableListing(schemaQualified: false);

        return $this->available = array_diff(self::REQUIRED_TABLES, $tables) === [];
    }
}
