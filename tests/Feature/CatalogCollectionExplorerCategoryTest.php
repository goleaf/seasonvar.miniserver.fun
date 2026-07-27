<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionExplorer;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionExplorerCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_category_and_subcategory_hierarchy_remains_visible_before_classification(): void
    {
        $root = CatalogCollectionCategory::query()
            ->where('slug', 'themes-and-genres')
            ->firstOrFail();
        $child = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();

        Livewire::test(CatalogCollectionExplorer::class)
            ->assertSeeHtml('data-collection-category-tree')
            ->assertSeeHtml('data-collection-category="themes-and-genres"')
            ->assertSeeHtml('data-collection-subcategory="detective-and-crime"')
            ->assertSeeHtml('data-collection-category="platforms-and-studios"')
            ->assertSeeHtml('data-collection-subcategory="netflix"')
            ->assertSeeText('Темы и жанры')
            ->assertSeeText('Детективы и криминал')
            ->assertSeeText('Платформы и студии')
            ->assertSeeText('Netflix')
            ->assertDontSeeHtml(
                'wire:click="selectCategory(\'themes-and-genres\', \'detective-and-crime\')"',
            )
            ->call('selectCategory', $root->slug, $child->slug)
            ->assertSet('category', $root->slug)
            ->assertSet('subcategory', $child->slug);
    }

    public function test_explorer_exposes_shareable_dependent_category_state_and_counts(): void
    {
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $child = CatalogCollectionCategory::query()->where('slug', 'detective-and-crime')->firstOrFail();
        $otherRoot = CatalogCollectionCategory::query()->where('slug', 'format')->firstOrFail();
        $this->collection('Корневая', $root);
        $this->collection('Дочерняя', $child);
        $this->collection('Без категории', uncategorized: true);
        $this->collection('Приватная', $child, CatalogCollectionVisibility::Private);

        Livewire::withQueryParams([
            'collections_category' => $root->slug,
            'collections_subcategory' => $child->slug,
        ])
            ->test(CatalogCollectionExplorer::class)
            ->assertSet('category', $root->slug)
            ->assertSet('subcategory', $child->slug)
            ->assertSeeHtml('name="collections_category"')
            ->assertSeeHtml('name="collections_subcategory"')
            ->assertSeeText('Темы и жанры')
            ->assertSeeText('Детективы и криминал')
            ->assertSeeText('Долгие истории')
            ->assertSeeText('Формат')
            ->assertDontSeeText(__('collections.directory.uncategorized'))
            ->assertSeeText('Дочерняя')
            ->assertDontSeeText('Корневая')
            ->set('category', $otherRoot->slug)
            ->assertSet('subcategory', '')
            ->assertSeeText('Формат')
            ->assertSeeText(__('collections.directory.category_empty'))
            ->call('resetFilters')
            ->assertSet('category', '')
            ->assertSet('subcategory', '')
            ->assertSet('search', '')
            ->assertSet('sort', 'featured');
    }

    public function test_invalid_category_url_state_normalizes_to_unfiltered_directory(): void
    {
        $this->collection('Публичная подборка');

        Livewire::withQueryParams([
            'collections_category' => 'forged-category',
            'collections_subcategory' => 'forged-subcategory',
        ])
            ->test(CatalogCollectionExplorer::class)
            ->assertSet('category', '')
            ->assertSet('subcategory', '')
            ->assertSeeText('Публичная подборка');
    }

    public function test_empty_state_distinguishes_category_filter_from_empty_directory(): void
    {
        Livewire::withQueryParams(['collections_category' => 'themes-and-genres'])
            ->test(CatalogCollectionExplorer::class)
            ->assertSeeText(__('collections.directory.category_empty'))
            ->assertSeeText(__('collections.directory.reset_all'));
    }

    public function test_uncategorized_control_is_hidden_while_zero_count_taxonomy_remains_visible(): void
    {
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $this->collection('Только распределённая подборка', $root);

        Livewire::test(CatalogCollectionExplorer::class)
            ->assertSeeText('Темы и жанры')
            ->assertSeeText('Формат')
            ->assertDontSeeText(__('collections.directory.uncategorized'));
    }

    public function test_selected_zero_count_category_remains_available_for_bookmarked_urls(): void
    {
        Livewire::withQueryParams(['collections_category' => 'format'])
            ->test(CatalogCollectionExplorer::class)
            ->assertSet('category', 'format')
            ->assertSeeText('Формат')
            ->assertSeeText(__('collections.directory.category_empty'))
            ->assertSeeText(__('collections.directory.reset_all'));
    }

    private function collection(
        string $name,
        ?CatalogCollectionCategory $category = null,
        CatalogCollectionVisibility $visibility = CatalogCollectionVisibility::Public,
        bool $uncategorized = false,
    ): CatalogCollection {
        if ($category === null
            && $visibility === CatalogCollectionVisibility::Public
            && ! $uncategorized) {
            $category = CatalogCollectionCategory::query()
                ->where('slug', 'themes-and-genres')
                ->firstOrFail();
        }

        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category?->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => $visibility,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => $visibility === CatalogCollectionVisibility::Public ? now() : null,
        ]);

        if ($visibility === CatalogCollectionVisibility::Public) {
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => CatalogTitle::factory()->create()->id,
                'position' => 1,
            ]);
        }

        return $collection;
    }
}
