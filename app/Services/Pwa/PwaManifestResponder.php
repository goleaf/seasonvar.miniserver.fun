<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Http\JsonResponse;

final class PwaManifestResponder
{
    public function response(): JsonResponse
    {
        $manifest = (array) config('pwa.manifest');

        return response()->json([
            'id' => '/',
            'name' => (string) ($manifest['name'] ?? 'Seasonvar'),
            'short_name' => (string) ($manifest['short_name'] ?? 'Seasonvar'),
            'description' => __('pwa.manifest.description'),
            'lang' => app()->getLocale(),
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => (string) ($manifest['theme_color'] ?? '#ecfdf5'),
            'background_color' => (string) ($manifest['background_color'] ?? '#f8fafc'),
            'icons' => [
                [
                    'src' => '/icons/pwa-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/pwa-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/icons/pwa-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ], 200, [
            'Cache-Control' => 'public, max-age=300',
            'Content-Type' => 'application/manifest+json',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
