<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogCollectionCoverSchemaRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_and_source_cover_columns_are_absent_from_the_current_schema(): void
    {
        foreach (['cover_disk', 'cover_path', 'cover_mime_type', 'cover_size', 'cover_version'] as $column) {
            $this->assertFalse(Schema::hasColumn('catalog_collections', $column), "Legacy catalog_collections.{$column} remains.");
            $this->assertNotContains($column, (new CatalogCollection)->getFillable());
        }

        foreach (['cover_source_path', 'cover_path', 'cover_content_hash'] as $column) {
            $this->assertFalse(Schema::hasColumn('catalog_collection_sources', $column), "Legacy catalog_collection_sources.{$column} remains.");
            $this->assertNotContains($column, (new CatalogCollectionSource)->getFillable());
        }
    }
}
