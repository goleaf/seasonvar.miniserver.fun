<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\CatalogAdministrationPage;
use App\Livewire\CatalogDiscoveryPage;
use App\Models\CatalogCollection;
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

    public function test_popular_discovery_contains_the_public_collection_explorer(): void
    {
        $collection = $this->collection();
        $this->assertSame(CatalogDiscoveryPage::class, Route::getRoutes()->getByName('discover.index')?->getActionName());

        $this->get(route('discover.index', ['type' => 'popular']))
            ->assertOk()
            ->assertSeeLivewire('catalog-discovery-page')
            ->assertSeeLivewire('collections.catalog-collection-explorer')
            ->assertSee('id="collections"', false)
            ->assertSeeText($collection->name)
            ->assertSee('name="collections_q"', false)
            ->assertSee('name="collections_sort"', false);
    }

    public function test_localized_popular_discovery_contains_the_same_collection_explorer(): void
    {
        $this->collection();
        $this->get(route('localized.discover.index', ['locale' => 'ru', 'type' => 'popular']))
            ->assertOk()
            ->assertSeeLivewire('collections.catalog-collection-explorer')
            ->assertSee('id="collections"', false);
    }

    public function test_collection_category_state_keeps_a_clean_noindex_canonical(): void
    {
        $title = CatalogTitle::factory()->create();
        LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        $canonical = route('discover.index', ['type' => 'popular']);

        $this->get($canonical)
            ->assertOk()
            ->assertSee('<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('hreflang=', false);

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

    public function test_removed_directory_and_legacy_urls_return_404_without_redirects(): void
    {
        foreach (['/collections', '/ru/collections', '/lists', '/lists/old-list', '/selections/old-selection', '/discover', '/ru/discover', '/recommendations', '/ru/recommendations', '/admin/collections'] as $uri) {
            $this->get($uri)->assertNotFound();
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
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(), 'owner_id' => null,
            'name' => 'Подборка внутри рекомендаций', 'description' => 'Проверка единой страницы.',
            'slug' => 'unified-'.Str::lower(Str::random(8)), 'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual, 'content_locale' => 'ru',
            'is_featured' => true, 'content_version' => 1, 'published_at' => now(),
        ]);
    }
}
