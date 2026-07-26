<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use RuntimeException;

final class VapidKeyGenerator
{
    /**
     * @return array{curve: string, public_key: string, private_key: string}
     */
    public function generate(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (
            ! $key instanceof \OpenSSLAsymmetricKey
            || ! openssl_pkey_export($key, $privateKey)
        ) {
            throw new RuntimeException('Не удалось создать VAPID private key.');
        }

        $details = openssl_pkey_get_details($key);
        $x = is_array($details) ? ($details['ec']['x'] ?? null) : null;
        $y = is_array($details) ? ($details['ec']['y'] ?? null) : null;

        if (! is_string($x) || ! is_string($y)) {
            throw new RuntimeException('Не удалось получить VAPID public key.');
        }

        return [
            'curve' => 'prime256v1',
            'public_key' => $this->base64Url("\x04".$x.$y),
            'private_key' => base64_encode($privateKey),
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
