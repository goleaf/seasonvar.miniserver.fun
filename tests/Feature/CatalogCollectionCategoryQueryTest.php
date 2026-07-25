<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogCollectionCategoryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tree_is_ordered_bilingual_and_uses_documented_fallbacks_without_n_plus_one(): void
    {
        app()->setLocale('en');
        $fallback = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();
        $fallback->translations()->where('locale', 'en')->delete();
        $slugOnly = CatalogCollectionCategory::query()
            ->where('slug', 'history')
            ->firstOrFail();
        $slugOnly->translations()->delete();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $tree = app(CatalogCollectionCategoryQuery::class)->activeTree();
        $queriesAfterLoad = count(DB::getQueryLog());
        $names = $tree
            ->flatMap(fn (CatalogCollectionCategory $root) => collect([$root, ...$root->children]))
            ->mapWithKeys(fn (CatalogCollectionCategory $category): array => [
                $category->slug => $category->display_name,
            ]);

        $this->assertSame(5, $tree->count());
        $this->assertSame(31, $tree->sum(fn (CatalogCollectionCategory $root): int => $root->children->count()));
        $this->assertSame('Themes and genres', $names->get('themes-and-genres'));
        $this->assertSame('Детективы и криминал', $names->get('detective-and-crime'));
        $this->assertSame('history', $names->get('history'));
        $this->assertSame($queriesAfterLoad, count(DB::getQueryLog()));
    }

    public function test_active_tree_excludes_archived_nodes_but_can_include_current_assignment(): void
    {
        $root = CatalogCollectionCategory::query()
            ->where('slug', 'mood-and-occasion')
            ->firstOrFail();
        $child = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();
        $root->forceFill(['is_active' => false])->save();
        $child->forceFill(['is_active' => false])->save();

        $query = app(CatalogCollectionCategoryQuery::class);
        $publicTree = $query->activeTree();
        $ownerTree = $query->activeTree($child->id);

        $this->assertNotContains('mood-and-occasion', $publicTree->pluck('slug')->all());
        $this->assertFalse(
            $publicTree
                ->flatMap(fn (CatalogCollectionCategory $category) => $category->children)
                ->contains('slug', 'detective-and-crime'),
        );
        $this->assertTrue(
            $ownerTree
                ->flatMap(fn (CatalogCollectionCategory $category) => $category->children)
                ->contains('slug', 'detective-and-crime'),
        );
    }
}
