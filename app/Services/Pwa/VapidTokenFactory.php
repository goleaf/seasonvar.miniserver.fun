<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use RuntimeException;

final class VapidTokenFactory
{
    public function configured(): bool
    {
        try {
            $this->configuration();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function authorization(string $endpoint, ?int $issuedAt = null): string
    {
        [$publicKey, $privateKey, $subject] = $this->configuration();
        $issuedAt ??= time();
        $header = $this->encodeJson(['typ' => 'JWT', 'alg' => 'ES256']);
        $payload = $this->encodeJson([
            'aud' => $this->audience($endpoint),
            'exp' => $issuedAt + 43_200,
            'sub' => $subject,
        ]);
        $signingInput = $header.'.'.$payload;

        if ($publicKey === '' || ! openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }

        return 'vapid t='.$signingInput.'.'.$this->base64Url($this->derToRaw($derSignature))
            .', k='.$publicKey;
    }

    /**
     * @return array{string, \OpenSSLAsymmetricKey, string}
     */
    private function configuration(): array
    {
        $publicKey = trim((string) config('pwa.push.public_key'));
        $publicKeyBytes = $this->publicKeyBytes($publicKey);
        $privateKey = $this->privateKey();
        $details = openssl_pkey_get_details($privateKey);
        $curve = is_array($details) ? ($details['ec']['curve_name'] ?? null) : null;
        $x = is_array($details) ? ($details['ec']['x'] ?? null) : null;
        $y = is_array($details) ? ($details['ec']['y'] ?? null) : null;

        if (
            ! in_array($curve, ['prime256v1', 'P-256'], true)
            || ! is_string($x)
            || ! is_string($y)
            || ! hash_equals($publicKeyBytes, "\x04".$x.$y)
        ) {
            throw new RuntimeException('VAPID public and private keys do not match.');
        }

        return [$publicKey, $privateKey, $this->subject()];
    }

    private function publicKeyBytes(string $encoded): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{80,120}\z/', $encoded) !== 1) {
            throw new RuntimeException('VAPID public key is invalid.');
        }

        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(
            strtr($encoded.str_repeat('=', $padding), '-_', '+/'),
            true,
        );

        if (! is_string($decoded) || strlen($decoded) !== 65 || $decoded[0] !== "\x04") {
            throw new RuntimeException('VAPID public key is invalid.');
        }

        return $decoded;
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $encoded = trim((string) config('pwa.push.private_key'));
        $pem = base64_decode($encoded, true);

        if (! is_string($pem) || ! str_contains($pem, 'BEGIN PRIVATE KEY')) {
            throw new RuntimeException('VAPID private key is unavailable.');
        }

        $key = openssl_pkey_get_private($pem);

        if (! $key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('VAPID private key is invalid.');
        }

        $details = openssl_pkey_get_details($key);

        if (
            ! is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ! is_array($details['ec'] ?? null)
        ) {
            throw new RuntimeException('VAPID private key must use an EC key.');
        }

        return $key;
    }

    private function subject(): string
    {
        $subject = trim((string) config('pwa.push.subject'));
        $email = str_starts_with($subject, 'mailto:')
            ? substr($subject, 7)
            : null;
        $validMail = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $parts = str_starts_with($subject, 'https://') ? parse_url($subject) : false;
        $validUrl = is_array($parts)
            && is_string($parts['host'] ?? null)
            && ! isset($parts['user'], $parts['pass']);

        if (! $validMail && ! $validUrl) {
            throw new RuntimeException('VAPID subject is invalid.');
        }

        return $subject;
    }

    private function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        if ($scheme !== 'https' || $host === '') {
            throw new RuntimeException('Web Push endpoint origin is invalid.');
        }

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    /** @param array<string, int|string> $value */
    private function encodeJson(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function derToRaw(string $der): string
    {
        $offset = 0;

        if ($this->byte($der, $offset++) !== 0x30) {
            throw new RuntimeException('VAPID signature sequence is invalid.');
        }

        $this->readLength($der, $offset);
        $r = $this->readInteger($der, $offset);
        $s = $this->readInteger($der, $offset);

        return $this->normalizeInteger($r).$this->normalizeInteger($s);
    }

    private function readInteger(string $der, int &$offset): string
    {
        if ($this->byte($der, $offset++) !== 0x02) {
            throw new RuntimeException('VAPID signature integer is invalid.');
        }

        $length = $this->readLength($der, $offset);
        $integer = substr($der, $offset, $length);
        $offset += $length;

        if (strlen($integer) !== $length) {
            throw new RuntimeException('VAPID signature is truncated.');
        }

        return $integer;
    }

    private function readLength(string $der, int &$offset): int
    {
        $length = $this->byte($der, $offset++);

        if (($length & 0x80) === 0) {
            return $length;
        }

        $bytes = $length & 0x7F;

        if ($bytes < 1 || $bytes > 2) {
            throw new RuntimeException('VAPID signature length is invalid.');
        }

        $length = 0;

        for ($index = 0; $index < $bytes; $index++) {
            $length = ($length << 8) | $this->byte($der, $offset++);
        }

        return $length;
    }

    private function normalizeInteger(string $integer): string
    {
        $integer = ltrim($integer, "\x00");

        if ($integer === '' || strlen($integer) > 32) {
            throw new RuntimeException('VAPID signature component is invalid.');
        }

        return str_pad($integer, 32, "\x00", STR_PAD_LEFT);
    }

    private function byte(string $value, int $offset): int
    {
        if (! isset($value[$offset])) {
            throw new RuntimeException('VAPID signature is truncated.');
        }

        return ord($value[$offset]);
    }
}
