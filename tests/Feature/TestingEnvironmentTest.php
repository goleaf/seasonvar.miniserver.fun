<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestingEnvironmentTest extends TestCase
{
    public function test_phpunit_uses_an_isolated_in_memory_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
