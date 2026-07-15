<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
}
