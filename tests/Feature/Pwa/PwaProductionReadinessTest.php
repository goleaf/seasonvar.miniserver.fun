<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Services\Operations\PwaProductionReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PwaProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_passes_only_for_https_assets_queue_schema_and_matching_vapid_keys(): void
    {
        $this->configureValidRuntime();

        $check = app(PwaProductionReadiness::class)->check();

        $this->assertSame('pass', $check->status);
        $this->assertNotContains(false, $check->metadata);
    }

    public function test_readiness_fails_closed_without_exposing_push_secrets(): void
    {
        $this->configureValidRuntime();
        config([
            'app.url' => 'http://catalog.example.com',
            'pwa.push.public_key' => 'mismatched-public-key',
            'queue.default' => 'sync',
        ]);

        $check = app(PwaProductionReadiness::class)->check();
        $serialized = json_encode($check->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('fail', $check->status);
        $this->assertFalse($check->metadata['https']);
        $this->assertFalse($check->metadata['vapid']);
        $this->assertFalse($check->metadata['queued_delivery']);
        $this->assertStringNotContainsString(
            (string) config('pwa.push.private_key'),
            $serialized,
        );
    }

    private function configureValidRuntime(): void
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);
        $publicKey = "\x04".$details['ec']['x'].$details['ec']['y'];

        config([
            'app.url' => 'https://catalog.example.com',
            'pwa.enabled' => true,
            'pwa.push.enabled' => true,
            'pwa.push.private_key' => base64_encode($privateKey),
            'pwa.push.public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'pwa.push.subject' => 'mailto:operations@example.com',
            'queue.default' => 'redis',
        ]);
    }
}
