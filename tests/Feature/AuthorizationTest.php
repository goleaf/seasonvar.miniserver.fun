<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_pages_remain_available_to_guests(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('titles.index'))->assertOk();
    }

    public function test_guest_can_view_catalog_stats(): void
    {
        $this
            ->get(route('stats'))
            ->assertOk();
    }
}
