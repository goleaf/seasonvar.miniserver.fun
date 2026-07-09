<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouteFallbackTest extends TestCase
{
    public function test_unknown_web_paths_redirect_to_the_catalog_homepage(): void
    {
        $this
            ->get('/nesushchestvuyushchaya-stranica')
            ->assertRedirect(route('home'));
    }

    public function test_unknown_api_paths_return_json_not_found(): void
    {
        $response = $this
            ->getJson('/api/nesushchestvuyushchii-endpoint')
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('content-type'));
    }
}
