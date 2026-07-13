<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'cache-architecture.stores.hot' => 'array',
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.stores.metrics' => 'array',
            'cache-architecture.framework_events.enabled' => false,
            'cache-architecture.warming.enabled' => false,
            'playback.allowed_hosts' => ['11cdn.org', 'media.example.com'],
            'playback.enforce_public_dns' => false,
            'security.external_playlist_enforce_public_dns' => false,
        ]);
    }
}
