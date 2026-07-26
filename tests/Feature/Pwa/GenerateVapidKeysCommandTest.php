<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class GenerateVapidKeysCommandTest extends TestCase
{
    public function test_command_generates_a_matching_p256_pair_without_persisting_it(): void
    {
        $exitCode = Artisan::call('pwa:vapid-generate', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('generated', $payload['status']);
        $this->assertSame('prime256v1', $payload['curve']);

        $privateKey = base64_decode($payload['private_key'], true);
        $publicKey = $this->decode($payload['public_key']);

        $this->assertIsString($privateKey);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $privateKey);
        $this->assertSame(65, strlen($publicKey));
        $this->assertSame("\x04", $publicKey[0]);

        $key = openssl_pkey_get_private($privateKey);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertSame(
            $publicKey,
            "\x04".$details['ec']['x'].$details['ec']['y'],
        );
    }

    private function decode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }
}
