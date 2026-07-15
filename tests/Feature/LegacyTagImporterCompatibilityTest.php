<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRelationSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyTagImporterCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_keeps_syncing_legacy_tags_before_the_canonical_schema_is_deployed(): void
    {
        config(['tags.canonical_schema' => false]);
        $title = CatalogTitle::factory()->create();
        Schema::drop('tag_provider_mappings');
        Schema::drop('tag_translations');

        $result = app(CatalogRelationSyncer::class)->sync($title, [[
            'type' => 'tag',
            'name' => 'Совместимый импортный тег',
        ]]);

        $this->assertSame(1, $result['tag']['count']);
        $this->assertDatabaseHas('tags', [
            'name' => 'Совместимый импортный тег',
            'slug' => 'sovmestimyi-importnyi-teg',
        ]);
        $this->assertDatabaseHas('catalog_title_tag', [
            'catalog_title_id' => $title->id,
        ]);
    }
}
