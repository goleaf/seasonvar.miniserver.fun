<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\ContentAudience;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionPublicDirectoryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_directory_groups_counts_for_only_the_current_page(): void
    {
        $collections = collect(range(1, 8))
            ->map(fn (int $number): CatalogCollection => $this->collection('Граница '.$number));
        $target = $collections->last();
        $visibleTitle = CatalogTitle::factory()->create();
        $hiddenTitle = CatalogTitle::factory()->create(['is_published' => false]);
        $authenticatedTitle = CatalogTitle::factory()->create([
            'audience' => ContentAudience::Authenticated,
        ]);

        foreach ($collections as $collection) {
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => CatalogTitle::factory()->create()->id,
                'position' => 1,
            ]);
        }

        foreach ([$visibleTitle, $hiddenTitle, $authenticatedTitle] as $position => $title) {
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $target->id,
                'catalog_title_id' => $title->id,
                'position' => $position + 2,
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $page = app(CatalogCollectionQuery::class)->publicDirectory(perPage: 6);
        $summary = collect($queries)->first(
            fn (QueryExecuted $query): bool => str_contains(
                str($query->sql)->lower()->toString(),
                'total_items_count',
            ),
        );

        $this->assertInstanceOf(QueryExecuted::class, $summary);
        $summarySql = str($summary->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();
        $pageIds = $page->getCollection()->modelKeys();
        $nonPageIds = $collections->pluck('id')->diff($pageIds)->values()->all();
        $integerBindings = collect($summary->bindings)
            ->filter(fn (mixed $binding): bool => is_int($binding))
            ->values()
            ->all();

        $this->assertStringContainsString('left join (select', $summarySql);
        $this->assertStringContainsString('as directory_counts', $summarySql);
        $this->assertStringContainsString(
            'group by items.catalog_collection_id',
            $summarySql,
        );
        $this->assertStringNotContainsString(
            '(select count(*) from catalog_collection_items where catalog_collections.id = catalog_collection_items.catalog_collection_id)',
            $summarySql,
        );
        $this->assertEqualsCanonicalizing($pageIds, array_values(array_intersect($integerBindings, $pageIds)));
        $this->assertSame([], array_values(array_intersect($integerBindings, $nonPageIds)));
        $this->assertLessThanOrEqual(12, count($queries));

        $hydratedTarget = $page->getCollection()->firstWhere('id', $target->id);
        $emptyCollection = $page->getCollection()->first(
            fn (CatalogCollection $collection): bool => $collection->id !== $target->id,
        );

        $this->assertInstanceOf(CatalogCollection::class, $hydratedTarget);
        $this->assertSame(4, (int) $hydratedTarget->total_items_count);
        $this->assertSame(2, (int) $hydratedTarget->visible_items_count);
        $this->assertInstanceOf(CatalogCollection::class, $emptyCollection);
        $this->assertSame(1, (int) $emptyCollection->total_items_count);
        $this->assertSame(1, (int) $emptyCollection->visible_items_count);
        $this->assertSame(8, $page->total());
        $this->assertSame(6, $page->perPage());
        $this->assertSame(1, $page->currentPage());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame(
            $pageIds,
            collect($pageIds)->sortDesc()->values()->all(),
        );
    }

    private function collection(string $name): CatalogCollection
    {
        $category = CatalogCollectionCategory::query()
            ->where('slug', 'themes-and-genres')
            ->firstOrFail();

        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'content_version' => 1,
            'published_at' => now(),
        ]);
    }
}
