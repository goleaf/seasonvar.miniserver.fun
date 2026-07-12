<?php

namespace App\Services\Catalog;

use Illuminate\Support\Str;

class CatalogStatsPosterUrlGuard
{
    /**
     * @var array<string, bool>
     */
    private array $blockedHosts = [];

    public function safeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '' || $this->blockedHost($host)) {
            return null;
        }

        return $url;
    }

    private function blockedHost(string $host): bool
    {
        return $this->blockedHosts[$host] ??= $this->resolveBlockedHost($host);
    }

    private function resolveBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            $ipv6Records = dns_get_record($host, DNS_AAAA);
            if (is_array($ipv6Records) && $ipv6Records !== []) {
                foreach ($ipv6Records as $record) {
                    if (is_string($record['ipv6'] ?? null)) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }

        return false;
    }
}
