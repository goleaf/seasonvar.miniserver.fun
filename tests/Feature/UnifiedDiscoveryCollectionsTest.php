<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogRecommendationType;
use App\Livewire\CatalogAdministrationPage;
use App\Livewire\CatalogDiscoveryPage;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnifiedDiscoveryCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_discovery_mode_contains_the_same_public_collection_explorer(): void
    {
        $collection = $this->collection();
        $this->assertSame(CatalogDiscoveryPage::class, Route::getRoutes()->getByName('discover.index')?->getActionName());

        foreach ([CatalogRecommendationType::Personalized, ...CatalogRecommendationType::publicCases()] as $type) {
            $this->get(route('discover.index', ['type' => $type->value]))
                ->assertOk()
                ->assertSeeLivewire('catalog-discovery-page')
                ->assertSeeLivewire('collections.catalog-collection-explorer')
                ->assertSee('id="collections"', false)
                ->assertSee('data-collection-category-tree', false)
                ->assertSeeText($collection->name)
                ->assertSee('name="collections_q"', false)
                ->assertSee('name="collections_sort"', false);
        }
    }

    public function test_localized_non_popular_discovery_contains_the_same_collection_explorer(): void
    {
        $this->collection();
        $this->get(route('localized.discover.index', ['locale' => 'en', 'type' => 'random']))
            ->assertOk()
            ->assertSeeLivewire('collections.catalog-collection-explorer')
            ->assertSee('id="collections"', false)
            ->assertSee('data-collection-category-tree', false)
            ->assertSeeText('Format');
    }

    public function test_collection_category_state_keeps_a_clean_noindex_canonical(): void
    {
        $title = CatalogTitle::factory()->create();
        LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        $popularCanonical = route('discover.index', ['type' => 'popular']);

        $this->get($popularCanonical)
            ->assertOk()
            ->assertSee('<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('hreflang=', false);

        foreach (['popular', 'top_rated'] as $type) {
            $canonical = route('discover.index', ['type' => $type]);

            foreach ([
                ['collections_category' => 'themes-and-genres'],
                [
                    'collections_category' => 'themes-and-genres',
                    'collections_subcategory' => 'detective-and-crime',
                ],
            ] as $query) {
                $this->get($canonical.'?'.http_build_query($query))
                    ->assertOk()
                    ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
                    ->assertSee('<meta name="robots" content="noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">', false)
                    ->assertDontSee('application/ld+json', false)
                    ->assertDontSee('hreflang=', false);
            }
        }
    }

    public function test_removed_directory_and_legacy_urls_return_404_without_redirects(): void
    {
        foreach (['/collections', '/ru/collections', '/lists', '/lists/old-list', '/selections/old-selection', '/recommendations', '/ru/recommendations', '/admin/collections'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_default_discovery_routes_redirect_to_popular_collections(): void
    {
        $popularCollections = route('discover.index', [
            'type' => CatalogRecommendationType::Popular->value,
        ]).'#collections';

        foreach (['/discover', '/discover/'] as $uri) {
            $this->get($uri)
                ->assertStatus(302)
                ->assertRedirect($popularCollections);
        }

        foreach (['ru', 'en'] as $locale) {
            $this->get("/{$locale}/discover")
                ->assertStatus(302)
                ->assertRedirect(route('localized.discover.index', [
                    'locale' => $locale,
                    'type' => CatalogRecommendationType::Popular->value,
                ]).'#collections');
        }
    }

    public function test_collection_detail_route_remains_available(): void
    {
        $collection = $this->collection();
        $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertOk()
            ->assertSeeText($collection->name);
    }

    public function test_admin_catalog_is_the_only_catalog_and_collection_administration_page(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertSame(CatalogAdministrationPage::class, Route::getRoutes()->getByName('admin.catalog')?->getActionName());
        $this->assertNull(Route::getRoutes()->getByName('admin.collections'));
        $this->actingAs($admin)->get(route('admin.catalog', ['section' => 'collections']))
            ->assertOk()
            ->assertSeeLivewire('catalog-administration-page')
            ->assertSeeLivewire('collections.catalog-collection-administration-manager');
    }

    private function collection(): CatalogCollection
    {
        $category = CatalogCollectionCategory::query()->create([
            'slug' => 'unified-discovery',
            'position' => 1,
            'is_active' => true,
        ]);
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(), 'owner_id' => null,
            'catalog_collection_category_id' => $category->id,
            'name' => 'Подборка внутри рекомендаций', 'description' => 'Проверка единой страницы.',
            'slug' => 'unified-'.Str::lower(Str::random(8)), 'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual, 'content_locale' => 'ru',
            'is_featured' => true, 'content_version' => 1, 'published_at' => now(),
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'position' => 1,
        ]);

        return $collection;
    }
}
