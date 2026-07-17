<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Country;
use App\Models\Genre;
use Composer\InstalledVersions;
use Tests\TestCase;
use Wddyousuf\AutoCache\Facades\AutoCache;
use Wddyousuf\AutoCache\Traits\Cacheable;

final class EloquentAutoCacheDependencyTest extends TestCase
{
    public function test_supported_eloquent_autocache_version_is_installed(): void
    {
        $this->assertTrue(InstalledVersions::isInstalled('wddyousuf/eloquent-autocache'));
        $this->assertMatchesRegularExpression(
            '/^v?0\.2\./',
            (string) InstalledVersions::getPrettyVersion('wddyousuf/eloquent-autocache'),
        );
        $this->assertTrue(class_exists(AutoCache::class));
        $this->assertTrue(trait_exists(Cacheable::class));
    }

    public function test_project_autocache_configuration_is_bounded_and_opt_in(): void
    {
        $this->assertTrue(config('autocache.enabled'));
        $this->assertSame('array', config('autocache.store'));
        $this->assertSame('opt-in', config('autocache.mode'));
        $this->assertSame(300, config('autocache.ttl'));
        $this->assertSame(0.1, config('autocache.ttl_jitter'));
        $this->assertFalse(config('autocache.use_tags'));
        $this->assertSame(5, config('autocache.lock_for'));
        $this->assertFalse(config('autocache.row_cache'));
        $this->assertFalse(config('autocache.cache_in_transactions'));
        $this->assertSame(0, config('autocache.swr'));
        $this->assertSame(100, config('autocache.max_rows'));
        $this->assertFalse(config('autocache.stats'));
        $this->assertSame([
            Country::class,
            Genre::class,
        ], config('autocache.models'));
        $this->assertFalse(config('autocache.pivot_invalidation.enabled'));
        $this->assertSame([], config('autocache.pivot_invalidation.map'));
        $this->assertFalse(config('cache.serializable_classes'));
    }
}
