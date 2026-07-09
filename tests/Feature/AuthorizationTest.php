<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_pages_remain_available_to_guests(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('titles.index'))->assertOk();
    }

    public function test_guest_cannot_view_catalog_stats(): void
    {
        $this->get(route('stats'))->assertForbidden();
    }

    public function test_authenticated_user_can_view_catalog_stats(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewCatalogStats'));

        $this
            ->actingAs($user)
            ->get(route('stats'))
            ->assertOk();
    }
}
