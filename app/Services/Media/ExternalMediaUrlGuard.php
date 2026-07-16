<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\VerifiedExternalUrlData;
use Illuminate\Support\Str;

final class ExternalMediaUrlGuard
{
    /** @var array<string, list<string>|null> */
    private array $resolvedHosts = [];

    /**
     * @param  list<string>  $allowedSchemes
     * @param  list<string>|null  $allowedHosts
     */
    public function verifiedExternalUrl(
        mixed $url,
        array $allowedSchemes = ['http', 'https'],
        ?array $allowedHosts = null,
    ): ?VerifiedExternalUrlData {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parts['port'] ?? $defaultPort);

        if (! in_array($scheme, $allowedSchemes, true)
            || $host === ''
            || ! in_array($port, [80, 443], true)
            || ($scheme === 'http' && $port !== 80)
            || ($scheme === 'https' && $port !== 443)
            || ! $this->hostAllowed($host, $allowedHosts)) {
            return null;
        }

        $addresses = $this->publicAddresses($host);

        if ($addresses === null) {
            return null;
        }

        return new VerifiedExternalUrlData($url, $host, $addresses[0] ?? null, $port);
    }

    /** @param list<string>|null $allowedHosts */
    private function hostAllowed(string $host, ?array $allowedHosts): bool
    {
        if ($allowedHosts === null) {
            return true;
        }

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = Str::lower(ltrim(trim($allowedHost), '*.'));

            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function publicAddresses(string $host): ?array
    {
        if (! (bool) config('security.external_playlist_enforce_public_dns', true)) {
            return [];
        }

        if (array_key_exists($host, $this->resolvedHosts)) {
            return $this->resolvedHosts[$host];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return $this->resolvedHosts[$host] = null;
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

        $addresses = array_values(array_unique($addresses));

        if ($addresses === []) {
            return $this->resolvedHosts[$host] = null;
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                return $this->resolvedHosts[$host] = null;
            }
        }

        return $this->resolvedHosts[$host] = $addresses;
    }
}
