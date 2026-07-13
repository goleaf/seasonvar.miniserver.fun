<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if ($this->shouldAttachReportOnlyCsp($response)) {
            $response->headers->set('Content-Security-Policy-Report-Only', $this->reportOnlyCsp());
        }

        return $response;
    }

    private function shouldAttachReportOnlyCsp(Response $response): bool
    {
        if (! (bool) config('security.csp_report_only.enabled', true)) {
            return false;
        }

        return str_starts_with(
            strtolower((string) $response->headers->get('Content-Type')),
            'text/html',
        );
    }

    private function reportOnlyCsp(): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'font-src' => ["'self'", 'data:'],
            'img-src' => $this->configuredSources('image_sources'),
            'media-src' => $this->configuredSources('media_sources'),
            'connect-src' => $this->configuredSources('connect_sources'),
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }

    /**
     * @return list<string>
     */
    private function configuredSources(string $key): array
    {
        $configured = config("security.csp_report_only.{$key}", []);

        if (! is_array($configured)) {
            return ["'self'"];
        }

        $sources = collect($configured)
            ->filter(fn (mixed $source): bool => is_string($source) && $this->isSafeCspSource($source))
            ->unique()
            ->values()
            ->all();

        return $sources === [] ? ["'self'"] : $sources;
    }

    private function isSafeCspSource(string $source): bool
    {
        if ($source === '' || preg_match('/[\s;\x00-\x1F\x7F]/', $source) === 1) {
            return false;
        }

        if (in_array($source, ["'self'", 'data:', 'blob:', 'https:'], true)) {
            return true;
        }

        return preg_match(
            '/\Ahttps:\/\/(?:\*\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[1-9][0-9]{0,4})?\z/i',
            $source,
        ) === 1;
    }
}
