<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Throwable;

final class PwaServiceWorkerResponder
{
    public function __construct(private readonly PwaBuildAssetResolver $assets) {}

    public function response(): Response
    {
        if (! config('pwa.enabled')) {
            return $this->javascriptResponse(<<<'JAVASCRIPT'
                const CACHE_PREFIX = 'seasonvar-';

                self.addEventListener('activate', (event) => {
                    event.waitUntil(
                        caches.keys()
                            .then((keys) => Promise.all(
                                keys
                                    .filter((key) => key.startsWith(CACHE_PREFIX))
                                    .map((key) => caches.delete(key)),
                            ))
                            .then(() => self.registration.unregister()),
                    );
                });
                JAVASCRIPT);
        }

        try {
            $assets = $this->assets->resolve();
        } catch (Throwable) {
            return $this->javascriptResponse(
                '/* PWA assets are temporarily unavailable; the installed worker remains active. */',
                503,
            );
        }

        $template = File::get(resource_path('pwa/service-worker.js'));
        $source = strtr($template, [
            '__CACHE_VERSION__' => json_encode($assets['version'], JSON_THROW_ON_ERROR),
            '__PRECACHE_URLS__' => json_encode($assets['urls'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '__PUSH_COPY__' => json_encode([
                'ru' => [
                    'title' => Lang::get('pwa.push.notification_title', locale: 'ru'),
                    'body' => Lang::get('pwa.push.notification_body', locale: 'ru'),
                ],
                'en' => [
                    'title' => Lang::get('pwa.push.notification_title', locale: 'en'),
                    'body' => Lang::get('pwa.push.notification_body', locale: 'en'),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->javascriptResponse($source);
    }

    private function javascriptResponse(string $source, int $status = 200): Response
    {
        return response($source, $status, [
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
