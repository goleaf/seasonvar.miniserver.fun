<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\Search\HeaderSearchSuggestionCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HeaderSearchSuggestionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.header-search-hot-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.header-search-domain-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.header-search-lock-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.header-search-metrics-test' => ['driver' => 'array', 'serialize' => true],
            'cache-architecture.stores.hot' => 'header-search-hot-test',
            'cache-architecture.stores.domain' => 'header-search-domain-test',
            'cache-architecture.stores.locks' => 'header-search-lock-test',
            'cache-architecture.stores.versions' => 'header-search-lock-test',
            'cache-architecture.stores.metrics' => 'header-search-metrics-test',
            'cache-architecture.domains.search-suggestions.jitter_percent' => 0,
        ]);
    }

    public function test_it_reuses_results_and_separates_locales_without_exposing_the_query_in_keys(): void
    {
        $calls = 0;
        $cache = app(HeaderSearchSuggestionCache::class);
        $rebuild = function () use (&$calls): array {
            return ['revision' => ++$calls];
        };

        app()->setLocale('ru');
        $first = $cache->remember('север', $rebuild);
        $second = $cache->remember('север', $rebuild);
        app()->setLocale('en');
        $english = $cache->remember('север', $rebuild);

        $this->assertSame(['revision' => 1], $first);
        $this->assertSame($first, $second);
        $this->assertSame(['revision' => 2], $english);
        $this->assertSame(2, $calls);

        $keys = Cache::store('header-search-domain-test')->getStore()->all();
        $this->assertStringNotContainsString('север', implode(' ', array_keys($keys)));
    }

    public function test_it_separates_cached_payloads_by_public_header_scope(): void
    {
        $cache = app(HeaderSearchSuggestionCache::class);

        $titles = $cache->remember('север', fn (): array => ['group' => 'titles'], 'header_titles');
        $portal = $cache->remember('север', fn (): array => ['group' => 'portal'], 'header_portal');

        $this->assertSame(['group' => 'titles'], $titles);
        $this->assertSame(['group' => 'portal'], $portal);
    }
}
