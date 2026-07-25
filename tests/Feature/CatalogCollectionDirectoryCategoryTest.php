<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionDirectoryCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_filters_root_child_and_uncategorized_assignments(): void
    {
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $child = CatalogCollectionCategory::query()->where('slug', 'detective-and-crime')->firstOrFail();
        $other = CatalogCollectionCategory::query()->where('slug', 'weekend')->firstOrFail();
        $rootCollection = $this->collection('Корневая', $root);
        $childCollection = $this->collection('Дочерняя', $child);
        $otherCollection = $this->collection('Другая', $other);
        $uncategorized = $this->collection('Без категории');
        $query = app(CatalogCollectionQuery::class);

        $rootResults = $query->publicDirectory(category: $root->slug);
        $childResults = $query->publicDirectory(category: $root->slug, subcategory: $child->slug);
        $uncategorizedResults = $query->publicDirectory(category: 'uncategorized');

        $this->assertEqualsCanonicalizing(
            [$rootCollection->id, $childCollection->id],
            $rootResults->getCollection()->modelKeys(),
        );
        $this->assertSame([$childCollection->id], $childResults->getCollection()->modelKeys());
        $this->assertSame([$uncategorized->id], $uncategorizedResults->getCollection()->modelKeys());
        $this->assertNotContains($otherCollection->id, $rootResults->getCollection()->modelKeys());
        $this->assertSame('Темы и жанры', $rootResults->getCollection()->firstWhere('id', $rootCollection->id)?->category?->display_name);
    }

    public function test_invalid_or_incompatible_category_filter_returns_empty_paginator(): void
    {
        $this->collection('Публичная');
        $query = app(CatalogCollectionQuery::class);

        $this->assertSame(0, $query->publicDirectory(category: 'not valid')->total());
        $this->assertSame(
            0,
            $query->publicDirectory(
                category: 'themes-and-genres',
                subcategory: 'weekend',
            )->total(),
        );
        $this->assertSame(
            0,
            $query->publicDirectory(
                category: null,
                subcategory: 'detective-and-crime',
            )->total(),
        );
    }

    public function test_two_phase_directory_preserves_deterministic_page_order(): void
    {
        foreach (range(1, 14) as $index) {
            $this->collection('Одинаковая '.$index, updatedAt: '2026-07-25 12:00:00');
        }
        $query = app(CatalogCollectionQuery::class);

        LengthAwarePaginator::currentPageResolver(static fn (string $pageName): int => $pageName === 'collectionsPage' ? 1 : 1);
        $first = $query->publicDirectory(perPage: 6);
        LengthAwarePaginator::currentPageResolver(static fn (string $pageName): int => $pageName === 'collectionsPage' ? 2 : 1);
        $second = $query->publicDirectory(perPage: 6);
        LengthAwarePaginator::currentPageResolver(static fn (): int => 1);

        $this->assertCount(6, $first);
        $this->assertCount(6, $second);
        $this->assertSame([], array_intersect(
            $first->getCollection()->modelKeys(),
            $second->getCollection()->modelKeys(),
        ));
        $this->assertSame(
            $first->getCollection()->modelKeys(),
            collect($first->getCollection()->modelKeys())->sortDesc()->values()->all(),
        );
    }

    public function test_expensive_summary_is_scoped_to_current_page_and_skipped_for_empty_results(): void
    {
        foreach (range(1, 30) as $index) {
            $this->collection('Бюджет '.$index);
        }
        $sql = [];
        DB::listen(static function (QueryExecuted $query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        app(CatalogCollectionQuery::class)->publicDirectory(perPage: 6);

        $idPageQuery = collect($sql)->first(
            fn (string $query): bool => str_contains($query, 'select "catalog_collections"."id"')
                && str_contains($query, ' limit '),
        );
        $summaryQuery = collect($sql)->first(
            fn (string $query): bool => str_contains($query, 'total_items_count')
                && str_contains($query, '"catalog_collections"."id" in'),
        );
        $countQuery = collect($sql)->first(
            fn (string $query): bool => str_contains($query, 'select count(*) as "aggregate"')
                && str_contains($query, 'from "catalog_collections"'),
        );

        $this->assertIsString($idPageQuery);
        $this->assertStringNotContainsString('catalog_collection_items', $idPageQuery);
        $this->assertIsString($summaryQuery);
        $this->assertMatchesRegularExpression(
            '/"catalog_collections"\\."id" in \\((?:\\d+, ){5}\\d+\\)/',
            $summaryQuery,
        );
        $this->assertIsString($countQuery);
        $this->assertStringNotContainsString('catalog_collection_items', $countQuery);

        $emptySql = [];
        DB::listen(static function (QueryExecuted $query) use (&$emptySql): void {
            $emptySql[] = strtolower($query->sql);
        });
        app(CatalogCollectionQuery::class)->publicDirectory(search: 'точно-отсутствующий-запрос');

        $this->assertFalse(collect($emptySql)->contains(
            fn (string $query): bool => str_contains($query, 'total_items_count'),
        ));
    }

    private function collection(
        string $name,
        ?CatalogCollectionCategory $category = null,
        ?string $updatedAt = null,
    ): CatalogCollection {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category?->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);

        if ($updatedAt !== null) {
            $collection->forceFill(['created_at' => $updatedAt, 'updated_at' => $updatedAt])->save();
        }

        return $collection;
    }
}
