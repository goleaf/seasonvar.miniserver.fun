<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\VerifiedExternalUrlData;
use Illuminate\Support\Str;

final class ExternalMediaUrlGuard
{
    /** @var list<string> */
    private const BLOCKED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001:2::/48',
        '2001:10::/28',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

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
        ?bool $enforcePublicDns = null,
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

        $addresses = $this->publicAddresses(
            $host,
            $enforcePublicDns ?? (bool) config('security.external_playlist_enforce_public_dns', true),
        );

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
    private function publicAddresses(string $host, bool $enforcePublicDns): ?array
    {
        if (! $enforcePublicDns) {
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
            if (! $this->isPublicAddress($address)) {
                return $this->resolvedHosts[$host] = null;
            }
        }

        return $this->resolvedHosts[$host] = $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        foreach (self::BLOCKED_NETWORKS as $network) {
            if ($this->addressInNetwork($address, $network)) {
                return false;
            }
        }

        return true;
    }

    private function addressInNetwork(string $address, string $network): bool
    {
        [$networkAddress, $prefixLength] = explode('/', $network, 2);
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($networkAddress);

        if ($addressBytes === false
            || $networkBytes === false
            || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $wholeBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($wholeBytes > 0
            && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
