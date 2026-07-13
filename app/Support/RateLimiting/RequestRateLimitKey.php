<?php

declare(strict_types=1);

namespace App\Support\RateLimiting;

use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogSeries;
use App\Livewire\CatalogTitlePlayer;
use App\Livewire\SeasonvarImportManager;
use App\Livewire\StatsDashboard;
use App\Livewire\ViewingActivity;
use Illuminate\Http\Request;

final class RequestRateLimitKey
{
    /** @var array<string, class-string> */
    private const LIVEWIRE_COMPONENTS = [
        'catalog-administration-manager' => CatalogAdministrationManager::class,
        'catalog-series' => CatalogSeries::class,
        'catalog-title-player' => CatalogTitlePlayer::class,
        'seasonvar-import-manager' => SeasonvarImportManager::class,
        'stats-dashboard' => StatsDashboard::class,
        'viewing-activity' => ViewingActivity::class,
    ];

    public function actor(Request $request): string
    {
        $user = $request->user();
        $identifier = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : null;
        $type = $identifier !== null ? 'user' : 'guest';
        $value = $identifier !== null ? (string) $identifier : (string) $request->ip();

        return $type.':'.$this->fingerprint($type.':'.$value);
    }

    public function livewireFeature(Request $request): string
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === [] || count($components) > 8) {
            return 'unknown';
        }

        $features = [];

        foreach ($components as $component) {
            $feature = $this->componentFeatures($component);

            if ($feature === null) {
                return 'unknown';
            }

            array_push($features, ...$feature);
        }

        $features = array_values(array_unique($features));
        sort($features, SORT_STRING);

        return $features === [] ? 'unknown' : 'feature:'.$this->fingerprint(implode('|', $features));
    }

    /** @return list<string>|null */
    private function componentFeatures(mixed $component): ?array
    {
        if (! is_array($component)
            || ! is_string($component['snapshot'] ?? null)
            || strlen($component['snapshot']) > 200_000) {
            return null;
        }

        $snapshot = json_decode($component['snapshot'], true);
        $name = is_array($snapshot) ? data_get($snapshot, 'memo.name') : null;
        $class = is_string($name) ? (self::LIVEWIRE_COMPONENTS[$name] ?? null) : null;
        $calls = $component['calls'] ?? [];

        if ($class === null || ! is_array($calls) || count($calls) > 8) {
            return null;
        }

        if ($calls === []) {
            return [$name.':updates'];
        }

        $publicMethods = get_class_methods($class);
        $features = [];

        foreach ($calls as $call) {
            $method = is_array($call) ? ($call['method'] ?? null) : null;

            if (! is_string($method) || ! in_array($method, $publicMethods, true)) {
                return null;
            }

            $features[] = $name.':'.$method;
        }

        return $features;
    }

    private function fingerprint(string $value): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $key = (string) config('cache.prefix', 'seasonvar');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
