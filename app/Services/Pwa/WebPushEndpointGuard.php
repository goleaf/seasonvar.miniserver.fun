<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Support\Str;

final class WebPushEndpointGuard
{
    public function allows(mixed $endpoint): bool
    {
        if (! is_string($endpoint)) {
            return false;
        }

        $endpoint = trim($endpoint);

        if ($endpoint === '' || strlen($endpoint) > 2048 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts) || isset($parts['user'], $parts['pass'])) {
            return false;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');

        if (
            $scheme !== 'https'
            || $host === ''
            || $path === ''
            || ($port !== null && $port !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host) !== 1
        ) {
            return false;
        }

        return collect((array) config('pwa.push.allowed_hosts', []))
            ->contains(fn (mixed $pattern): bool => is_string($pattern)
                && $this->matchesHost($host, Str::lower(trim($pattern))));
    }

    private function matchesHost(string $host, string $pattern): bool
    {
        if (! str_starts_with($pattern, '*.')) {
            return hash_equals($pattern, $host);
        }

        $suffix = substr($pattern, 1);
        $base = substr($pattern, 2);

        return $host !== $base && str_ends_with($host, $suffix);
    }
}
