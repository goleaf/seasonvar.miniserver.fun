<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Jobs\WarmCatalogCaches;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImportedTitleCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_group_completion_defers_global_invalidation_until_run_finalization(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();
        $versions = app(CacheVersionRegistry::class);
        $homepageVersion = $versions->version(CacheDomain::Homepage);
        $titleVersion = $versions->version(CacheDomain::TitleDetail, 'title:41');

        DB::beginTransaction();
        app(CatalogCacheInvalidator::class)->importedTitleChanged(41);

        $this->assertSame($titleVersion, $versions->version(CacheDomain::TitleDetail, 'title:41'));
        Queue::assertNothingPushed();
        DB::commit();

        $this->assertSame($homepageVersion, $versions->version(CacheDomain::Homepage));
        $this->assertGreaterThan($titleVersion, $versions->version(CacheDomain::TitleDetail, 'title:41'));
        $work = app(CatalogCacheWarmRequestStore::class)->claim(10);
        $this->assertNotNull($work);
        $this->assertSame([41], $work->titleIds);
        $this->assertFalse($work->refresh);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_run_finalization_invalidates_tag_discovery_domains_once(): void
    {
        config(['cache-architecture.warming.enabled' => false]);
        $versions = app(CacheVersionRegistry::class);
        $tagsVersion = $versions->version(CacheDomain::Tags);
        $suggestionsVersion = $versions->version(CacheDomain::SearchSuggestions);

        app(CatalogCacheInvalidator::class)->catalogChanged();

        $this->assertGreaterThan($tagsVersion, $versions->version(CacheDomain::Tags));
        $this->assertGreaterThan($suggestionsVersion, $versions->version(CacheDomain::SearchSuggestions));
    }

    public function test_mass_import_can_invalidate_a_title_without_scheduling_a_warm(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();
        $title = CatalogTitle::factory()->create();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Публичная подборка',
            'slug' => 'publichnaia-podborka',
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $versions = app(CacheVersionRegistry::class);
        $titleVersion = $versions->version(CacheDomain::TitleDetail, 'title:'.$title->id);
        $homepageVersion = $versions->version(CacheDomain::Homepage);
        $collectionsVersion = $versions->version(CacheDomain::Collections);

        app(CatalogCacheInvalidator::class)->importedTitleChanged(
            $title->id,
            warm: false,
            invalidateCollections: false,
        );

        $this->assertGreaterThan(
            $titleVersion,
            $versions->version(CacheDomain::TitleDetail, 'title:'.$title->id),
        );
        $this->assertSame($homepageVersion, $versions->version(CacheDomain::Homepage));
        $this->assertSame($collectionsVersion, $versions->version(CacheDomain::Collections));
        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertNotPushed(WarmCatalogCaches::class);
    }

    public function test_targeted_import_keeps_collection_dependent_invalidation_and_warm(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();
        $title = CatalogTitle::factory()->create();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Целевая подборка',
            'slug' => 'tselevaia-podborka',
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $versions = app(CacheVersionRegistry::class);
        $homepageVersion = $versions->version(CacheDomain::Homepage);
        $collectionsVersion = $versions->version(CacheDomain::Collections);

        app(CatalogCacheInvalidator::class)->importedTitleChanged($title->id);

        $this->assertGreaterThan($homepageVersion, $versions->version(CacheDomain::Homepage));
        $this->assertGreaterThan($collectionsVersion, $versions->version(CacheDomain::Collections));
        $this->assertNotNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }
}
