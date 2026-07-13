<?php

namespace App\Services\Catalog;

use App\DTOs\VerifiedExternalUrlData;
use Illuminate\Support\Str;

class CatalogStatsPosterUrlGuard
{
    /**
     * @var array<string, list<string>>
     */
    private array $publicHosts = [];

    public function safeUrl(mixed $url): ?string
    {
        return $this->verifiedUrl($url)?->url;
    }

    public function verifiedUrl(mixed $url): ?VerifiedExternalUrlData
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
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

        $addresses = $this->publicAddresses($host);

        return $addresses === null
            ? null
            : new VerifiedExternalUrlData($url, $host, $addresses[0]);
    }

    /** @return list<string>|null */
    private function publicAddresses(string $host): ?array
    {
        if (array_key_exists($host, $this->publicHosts)) {
            return $this->publicHosts[$host];
        }

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

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $this->publicHosts[$host] = array_values(array_unique($addresses));
    }
}
