<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->app->setLocale((string) config('account-settings.default_locale', 'ru'));
        $this->withHeader('Accept-Language', 'ru');
        config([
            'cache-architecture.stores.hot' => 'array',
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.stores.metrics' => 'array',
            'cache-architecture.framework_events.enabled' => false,
            'cache-architecture.warming.enabled' => false,
            'cache-architecture.page_cache.warming_enabled' => false,
            // RefreshDatabase applies optional domain migrations before each test.
            'catalog-collections.schema_available' => true,
            'playback.allowed_hosts' => ['11cdn.org', 'media.example.com'],
            'playback.enforce_public_dns' => false,
            'security.external_playlist_enforce_public_dns' => false,
            'tags.canonical_schema' => true,
        ]);

        Cache::store('array')->clear();
    }
}
