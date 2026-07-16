<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiFoundationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_root_discovers_v1_and_openapi_without_infrastructure_details(): void
    {
        $response = $this->getJson('/api');

        $response->assertOk()
            ->assertJsonPath('data.current_version', 'v1')
            ->assertJsonPath('data.base_url', url('/api/v1'))
            ->assertJsonPath('data.openapi_url', url('/api/openapi.json'))
            ->assertJsonMissingPath('data.framework_version')
            ->assertJsonMissingPath('data.database');
    }

    public function test_v1_config_and_health_are_safe_and_stable(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.locale', 'ru')
            ->assertJsonPath('data.pagination.maximum_per_page', 50)
            ->assertJsonPath('data.user_rating.minimum', 1)
            ->assertJsonStructure(['data' => ['playback' => ['formats', 'qualities']]]);

        $health = $this->getJson('/api/v1/health');

        $health
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                    'server_time' => now()->utc()->toISOString(),
                    'api_version' => 'v1',
                ],
            ]);
        $this->assertStringContainsString('private', (string) $health->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $health->headers->get('Cache-Control'));
    }

    public function test_v1_config_reports_the_locale_negotiated_for_the_request(): void
    {
        config(['app.locale' => 'ru']);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_openapi_document_is_valid_json_and_describes_bearer_auth(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('components.securitySchemes.bearerAuth.scheme', 'bearer')
            ->assertJsonPath('paths./api/v1/health.get.operationId', 'getApiHealth');
    }
}
