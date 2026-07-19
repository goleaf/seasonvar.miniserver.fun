<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionTitleQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_title_collections_start_from_the_indexed_item_membership(): void
    {
        $title = CatalogTitle::factory()->create();
        $featured = $this->collection('featured-title-collection', true);
        $regular = $this->collection('regular-title-collection');
        $private = $this->collection('private-title-collection', visibility: CatalogCollectionVisibility::Private);

        foreach ([$featured, $regular, $private] as $position => $collection) {
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => $title->id,
                'position' => $position + 1,
            ]);
        }

        $collectionSql = null;
        DB::listen(function (QueryExecuted $query) use (&$collectionSql): void {
            $sql = str($query->sql)->replace(['`', '"'], '')->lower()->squish()->toString();

            if (str_contains($sql, 'from catalog_collections') && str_contains($sql, 'limit 12')) {
                $collectionSql = $sql;
            }
        });

        $collections = app(CatalogCollectionQuery::class)->publicForTitle($title->id, 12);

        $this->assertSame([$featured->id, $regular->id], $collections->modelKeys());
        $this->assertIsString($collectionSql);
        $this->assertStringContainsString(
            'catalog_collections.id in (select catalog_collection_id from catalog_collection_items where catalog_title_id = ?)',
            $collectionSql,
        );
        $this->assertStringNotContainsString(
            'exists (select * from catalog_collection_items where catalog_collections.id = catalog_collection_items.catalog_collection_id and catalog_title_id = ?)',
            $collectionSql,
        );
    }

    private function collection(
        string $slug,
        bool $featured = false,
        CatalogCollectionVisibility $visibility = CatalogCollectionVisibility::Public,
    ): CatalogCollection {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => null,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'type' => CatalogCollectionType::Editorial,
            'visibility' => $visibility,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => $featured,
            'published_at' => now(),
        ]);
    }
}
