<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicHttpCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_response_has_shared_cache_policy_and_validators(): void
    {
        CatalogTitle::factory()->create();

        $response = $this->getJson(route('api.titles.index'));

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertHeader('ETag')
            ->assertHeader('Last-Modified')
            ->assertHeader('Vary', 'Accept, Accept-Encoding');
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_matching_public_api_etag_returns_not_modified_without_a_body(): void
    {
        CatalogTitle::factory()->create();
        $etag = (string) $this->getJson(route('api.titles.index'))->headers->get('ETag');

        $response = $this->withHeader('If-None-Match', $etag)->getJson(route('api.titles.index'));

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
    }

    public function test_public_api_head_response_uses_the_same_entity_tag_as_get(): void
    {
        CatalogTitle::factory()->create();

        $get = $this->getJson(route('api.titles.index'));
        $head = $this->json('HEAD', route('api.titles.index'));

        $get->assertOk()->assertHeader('ETag');
        $head->assertOk()->assertHeader('ETag');
        $this->assertSame($get->headers->get('ETag'), $head->headers->get('ETag'));
        $this->assertSame('', $head->getContent());
    }

    public function test_livewire_and_signed_playback_responses_are_never_marked_public(): void
    {
        $response = $this->get(route('titles.index'));

        $response->assertOk();
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_public_sitemap_document_is_shared_cacheable_without_starting_a_session(): void
    {
        $response = $this->get(route('sitemap.index'));

        $response->assertOk()
            ->assertHeader('ETag')
            ->assertHeader('Last-Modified');
        $this->assertStringContainsString('s-maxage=1800', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_public_response_fails_closed_to_no_store_when_version_registry_is_unavailable(): void
    {
        CatalogTitle::factory()->create();
        config([
            'cache.stores.unavailable-http-version-test' => ['driver' => 'unsupported-http-version-test'],
            'cache-architecture.stores.versions' => 'unavailable-http-version-test',
        ]);

        $response = $this->getJson(route('api.titles.index'));

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($response->headers->has('ETag'));
        $this->assertFalse($response->headers->has('Last-Modified'));
    }

    public function test_api_request_with_authorization_header_is_never_shared_cacheable(): void
    {
        $response = $this->withToken('invalid-mobile-token')->getJson('/api/v1/config');

        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('ETag'));
        $this->assertFalse($response->headers->has('Last-Modified'));
    }
}
