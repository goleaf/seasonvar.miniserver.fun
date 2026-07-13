<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\VerifiedExternalUrlData;
use Illuminate\Support\Str;

class PlaybackSourceUrlGuard
{
    /** @var array<string, list<string>> */
    private array $publicHosts = [];

    public function safeExternalUrl(mixed $url): ?string
    {
        return $this->verifiedExternalUrl($url)?->url;
    }

    public function verifiedExternalUrl(mixed $url): ?VerifiedExternalUrlData
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user'], $parts['pass'])) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;

        if ($scheme !== 'https' || $host === '' || ($port !== null && $port !== 443)) {
            return null;
        }

        if (! $this->allowedHost($host)) {
            return null;
        }

        $addresses = $this->publicAddresses($host);

        if ($addresses === null) {
            return null;
        }

        return new VerifiedExternalUrlData($url, $host, $addresses[0] ?? null);
    }

    private function allowedHost(string $host): bool
    {
        foreach ((array) config('playback.allowed_hosts', []) as $allowedHost) {
            $allowedHost = Str::lower(ltrim(trim((string) $allowedHost), '*.'));

            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function publicAddresses(string $host): ?array
    {
        if (! (bool) config('playback.enforce_public_dns', true)) {
            return [];
        }

        if (array_key_exists($host, $this->publicHosts)) {
            return $this->publicHosts[$host];
        }

        $addresses = $this->resolvePublicAddresses($host);

        if ($addresses === null) {
            return null;
        }

        return $this->publicHosts[$host] = $addresses;
    }

    /** @return list<string>|null */
    private function resolvePublicAddresses(string $host): ?array
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);
        $ipv6Records = dns_get_record($host, DNS_AAAA);

        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            return null;
        }

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return array_values(array_unique($addresses));
    }
}
