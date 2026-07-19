<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouteFallbackTest extends TestCase
{
    public function test_unknown_web_paths_return_not_found_without_redirecting(): void
    {
        $this
            ->get('/nesushchestvuyushchaya-stranica')
            ->assertNotFound();
    }

    public function test_unknown_api_paths_return_json_not_found(): void
    {
        $response = $this
            ->getJson('/api/nesushchestvuyushchii-endpoint')
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('content-type'));
    }

    public function test_unknown_api_paths_return_the_stable_error_envelope(): void
    {
        $response = $this->getJson('/api/nesushchestvuyushchii-endpoint');

        $response->assertNotFound()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Ресурс не найден.')
            ->assertJsonStructure(['code', 'message', 'request_id']);

        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('request_id'),
        );
    }
}
