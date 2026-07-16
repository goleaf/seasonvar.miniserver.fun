<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class TechnicalIssueContext
{
    public function titleUrl(CatalogTitle $title): string
    {
        return $this->url($this->encode([
            'target' => 'title',
            'title_id' => $title->id,
            'feature' => 'title',
            'route' => 'titles.show',
            'path' => parse_url(route('titles.show', $title), PHP_URL_PATH),
        ]));
    }

    public function playerUrl(
        CatalogTitle $title,
        ?Season $season,
        ?Episode $episode,
        ?LicensedMedia $media,
        ?string $audioLanguage = null,
        ?string $subtitleLanguage = null,
    ): string {
        return $this->url($this->encode([
            'target' => $media !== null ? 'media' : ($episode !== null ? 'episode' : 'title'),
            'title_id' => $title->id,
            'season_id' => $season?->id,
            'episode_id' => $episode?->id,
            'media_id' => $media?->id,
            'feature' => 'player',
            'route' => 'titles.show',
            'path' => parse_url(route('titles.show', $title), PHP_URL_PATH),
            'quality' => $media?->quality,
            'audio_language' => $this->languageCode($audioLanguage),
            'subtitle_language' => $this->languageCode($subtitleLanguage),
            'player' => 'catalog-player-v1',
        ]));
    }

    public function featureUrl(string $feature): string
    {
        $feature = in_array($feature, config('technical-issues.feature_codes', []), true) ? $feature : 'general';
        $routeName = request()->route()?->getName();
        $routeName = is_string($routeName) && ! Str::contains($routeName, ['password', 'verification', 'oauth', 'token'])
            ? $routeName
            : null;
        $path = request()->getPathInfo();
        $path = Str::contains($path, ['/reset-password', '/email/verify', '/oauth']) ? null : $path;

        return $this->url($this->encode([
            'target' => 'feature',
            'feature' => $feature,
            'route' => $routeName,
            'path' => $path,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function decode(string $token): ?array
    {
        if ($token === '' || mb_strlen($token) > 4096) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['version'] ?? null) !== 1 || ! is_int($payload['issued_at'] ?? null)) {
            return null;
        }

        $ttl = max(5, (int) config('technical-issues.context_ttl_minutes', 120));

        if ($payload['issued_at'] < now()->subMinutes($ttl)->getTimestamp() || $payload['issued_at'] > now()->addMinute()->getTimestamp()) {
            return null;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        $payload = array_filter([
            'version' => 1,
            'issued_at' => now()->getTimestamp(),
            ...$payload,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function url(string $token): string
    {
        $locale = App::getLocale();
        $parameters = ['context' => $token];

        if (in_array($locale, config('technical-issues.supported_locales', []), true)) {
            return route('localized.issues.create', ['locale' => $locale, ...$parameters]);
        }

        return route('issues.create', $parameters);
    }

    private function languageCode(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $value) === 1
            ? $value
            : null;
    }
}
