<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\DeploymentCheck;
use App\Services\Pwa\PwaBuildAssetResolver;
use App\Services\Pwa\VapidTokenFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PwaProductionReadiness
{
    public function __construct(
        private readonly PwaBuildAssetResolver $assets,
        private readonly VapidTokenFactory $tokens,
    ) {}

    public function check(): DeploymentCheck
    {
        $checks = [
            'pwa_enabled' => (bool) config('pwa.enabled'),
            'https' => parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https',
            'routes' => Route::has('pwa.manifest')
                && Route::has('pwa.worker')
                && Route::has('pwa.offline'),
            'assets' => $this->assetsAreReady(),
            'push_enabled' => (bool) config('pwa.push.enabled'),
            'vapid' => $this->tokens->configured(),
            'subscription_table' => $this->subscriptionTableExists(),
            'queued_delivery' => ! in_array((string) config('queue.default'), ['', 'null', 'sync'], true),
        ];
        $ready = ! in_array(false, $checks, true);

        return new DeploymentCheck(
            'pwa_push',
            $ready ? 'pass' : 'fail',
            $ready
                ? 'PWA/Web Push production prerequisites настроены.'
                : 'PWA/Web Push требует HTTPS, assets, migration, VAPID и асинхронную очередь.',
            $checks,
        );
    }

    private function assetsAreReady(): bool
    {
        try {
            $assets = $this->assets->resolve();
            $icon192 = getimagesize(public_path('icons/pwa-192.png'));
            $icon512 = getimagesize(public_path('icons/pwa-512.png'));
            $maskable = getimagesize(public_path('icons/pwa-maskable-512.png'));

            return collect([
                '/offline',
                '/manifest.webmanifest',
                '/icons/pwa-192.png',
                '/icons/pwa-512.png',
                '/icons/pwa-maskable-512.png',
            ])->every(fn (string $url): bool => in_array($url, $assets['urls'], true))
                && File::isFile(resource_path('pwa/service-worker.js'))
                && $icon192 !== false
                && [$icon192[0], $icon192[1]] === [192, 192]
                && $icon512 !== false
                && [$icon512[0], $icon512[1]] === [512, 512]
                && $maskable !== false
                && [$maskable[0], $maskable[1]] === [512, 512];
        } catch (Throwable) {
            return false;
        }
    }

    private function subscriptionTableExists(): bool
    {
        try {
            return Schema::hasTable('web_push_subscriptions');
        } catch (Throwable) {
            return false;
        }
    }
}
