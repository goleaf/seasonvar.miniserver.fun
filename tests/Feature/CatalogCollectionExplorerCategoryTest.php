<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionExplorer;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionExplorerCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_explorer_exposes_shareable_dependent_category_state_and_counts(): void
    {
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $child = CatalogCollectionCategory::query()->where('slug', 'detective-and-crime')->firstOrFail();
        $otherRoot = CatalogCollectionCategory::query()->where('slug', 'format')->firstOrFail();
        $this->collection('Корневая', $root);
        $this->collection('Дочерняя', $child);
        $this->collection('Без категории');
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
            ->assertDontSeeText('Долгие истории')
            ->assertDontSeeText('Формат')
            ->assertSeeText(__('collections.directory.uncategorized'))
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

    public function test_uncategorized_control_is_hidden_when_it_cannot_return_results(): void
    {
        $root = CatalogCollectionCategory::query()->where('slug', 'themes-and-genres')->firstOrFail();
        $this->collection('Только распределённая подборка', $root);

        Livewire::test(CatalogCollectionExplorer::class)
            ->assertSeeText('Темы и жанры')
            ->assertDontSeeText('Формат')
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
    ): CatalogCollection {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category?->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => $visibility,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => $visibility === CatalogCollectionVisibility::Public ? now() : null,
        ]);
    }
}
