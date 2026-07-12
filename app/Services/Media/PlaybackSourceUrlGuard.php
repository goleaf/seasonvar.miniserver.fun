<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Str;

class PlaybackSourceUrlGuard
{
    /** @var array<string, bool> */
    private array $publicHosts = [];

    public function safeExternalUrl(mixed $url): ?string
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

        if (! $this->allowedHost($host) || ! $this->publicHost($host)) {
            return null;
        }

        return $url;
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

    private function publicHost(string $host): bool
    {
        if (! (bool) config('playback.enforce_public_dns', true)) {
            return true;
        }

        return $this->publicHosts[$host] ??= $this->resolvePublicHost($host);
    }

    private function resolvePublicHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
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
            return false;
        }

        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
