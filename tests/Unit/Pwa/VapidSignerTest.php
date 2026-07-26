<?php

declare(strict_types=1);

namespace Tests\Unit\Pwa;

use App\Services\Pwa\VapidTokenFactory;
use Tests\TestCase;

final class VapidSignerTest extends TestCase
{
    public function test_token_is_es256_signed_for_the_exact_push_origin_and_has_a_short_expiry(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        config([
            'pwa.push.private_key' => base64_encode($privateKey),
            'pwa.push.public_key' => $publicKey,
            'pwa.push.subject' => 'mailto:operations@example.com',
        ]);

        $authorization = app(VapidTokenFactory::class)->authorization(
            'https://fcm.googleapis.com/fcm/send/private-capability',
            1_800_000_000,
        );

        $this->assertStringStartsWith('vapid t=', $authorization);
        $this->assertStringContainsString(', k='.$publicKey, $authorization);

        $jwt = str($authorization)->after('vapid t=')->before(', k=')->toString();
        [$header, $payload, $signature] = array_map(
            fn (string $part): string => $this->decode($part),
            explode('.', $jwt),
        );

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode($header, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([
            'aud' => 'https://fcm.googleapis.com',
            'exp' => 1_800_043_200,
            'sub' => 'mailto:operations@example.com',
        ], json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(64, strlen($signature));
    }

    public function test_configuration_requires_a_matching_p256_key_pair_and_valid_subject(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $tokens = app(VapidTokenFactory::class);

        config([
            'pwa.push.private_key' => base64_encode($privateKey),
            'pwa.push.public_key' => $publicKey,
            'pwa.push.subject' => 'mailto:operations@example.com',
        ]);

        $this->assertTrue($tokens->configured());

        [, $otherPublicKey] = $this->keyPair();
        config(['pwa.push.public_key' => $otherPublicKey]);
        $this->assertFalse($tokens->configured());

        config([
            'pwa.push.public_key' => $publicKey,
            'pwa.push.subject' => 'javascript:invalid',
        ]);
        $this->assertFalse($tokens->configured());
    }

    /** @return array{string, string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);
        $this->assertIsArray($details['ec'] ?? null);

        $publicKey = "\x04".$details['ec']['x'].$details['ec']['y'];

        return [$privateKey, rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=')];
    }

    private function decode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }
}
