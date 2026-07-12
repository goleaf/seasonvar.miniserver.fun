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
            'playback.allowed_hosts' => ['11cdn.org', 'media.example.com'],
            'playback.enforce_public_dns' => false,
        ]);
    }
}
