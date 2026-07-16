<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Enums\TechnicalIssueTargetType;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final readonly class TechnicalIssueTargetResolver
{
    public function __construct(private TechnicalIssueContext $contexts) {}

    public function resolve(User $user, string $contextToken, string $fallbackFeature = 'general'): TechnicalIssueTargetData
    {
        $payload = $this->contexts->decode($contextToken);

        if ($payload === null) {
            if ($contextToken !== '') {
                throw new TechnicalIssueActionException('issues.errors.context_expired');
            }

            return $this->featureTarget($fallbackFeature);
        }

        if (($payload['target'] ?? null) === 'feature') {
            return $this->featureTarget(
                is_string($payload['feature'] ?? null) ? $payload['feature'] : $fallbackFeature,
                $this->safeRouteName($payload['route'] ?? null),
                $this->safePath($payload['path'] ?? null),
            );
        }

        $titleId = $this->positiveId($payload['title_id'] ?? null);
        $title = $titleId !== null
            ? CatalogTitle::query()->availableTo($user)->find($titleId, ['id', 'slug', 'title'])
            : null;

        if (! $title instanceof CatalogTitle) {
            throw new TechnicalIssueActionException('issues.errors.target_unavailable');
        }

        $seasonId = $this->positiveId($payload['season_id'] ?? null);
        $season = $seasonId !== null
            ? Season::query()->availableTo($user)->where('catalog_title_id', $title->id)->find($seasonId, ['id', 'catalog_title_id', 'number', 'kind'])
            : null;
        $episodeId = $this->positiveId($payload['episode_id'] ?? null);
        $episode = $episodeId !== null && $season instanceof Season
            ? Episode::query()->availableTo($user)->where('season_id', $season->id)->find($episodeId, ['id', 'season_id', 'number', 'kind', 'title'])
            : null;
        $mediaId = $this->positiveId($payload['media_id'] ?? null);
        $media = $mediaId !== null
            ? LicensedMedia::query()
                ->availableTo($user)
                ->forAvailableReleases($user)
                ->where('catalog_title_id', $title->id)
                ->when($season instanceof Season, fn ($query) => $query->where('season_id', $season->id))
                ->when($episode instanceof Episode, fn ($query) => $query->where('episode_id', $episode->id))
                ->find($mediaId, ['id', 'catalog_title_id', 'season_id', 'episode_id', 'duration_seconds', 'health_status', 'quality', 'translation_name'])
            : null;

        if ($seasonId !== null && ! $season instanceof Season
            || $episodeId !== null && ! $episode instanceof Episode
            || $mediaId !== null && ! $media instanceof LicensedMedia) {
            throw new TechnicalIssueActionException('issues.errors.target_unavailable');
        }

        $translationId = $this->positiveId($payload['translation_id'] ?? null);
        $translation = $translationId !== null
            ? Translation::query()->whereHas('catalogTitles', fn ($query) => $query->whereKey($title->id))->find($translationId, ['id', 'name'])
            : null;

        if ($translation === null && $translationId === null && $media instanceof LicensedMedia && is_string($media->translation_name)) {
            $translationName = trim($media->translation_name);
            $translation = $translationName !== ''
                ? Translation::query()
                    ->where('name', $translationName)
                    ->whereHas('catalogTitles', fn ($query) => $query->whereKey($title->id))
                    ->first(['id', 'name'])
                : null;
        }

        if ($translationId !== null && ! $translation instanceof Translation) {
            throw new TechnicalIssueActionException('issues.errors.invalid_target');
        }

        $targetType = match (true) {
            $media instanceof LicensedMedia => TechnicalIssueTargetType::Media,
            $episode instanceof Episode => TechnicalIssueTargetType::Episode,
            $season instanceof Season => TechnicalIssueTargetType::Season,
            $translation instanceof Translation => TechnicalIssueTargetType::Translation,
            default => TechnicalIssueTargetType::Title,
        };
        $label = $title->title;

        if ($season instanceof Season) {
            $label .= ' · '.__('issues.target_summary.season', ['number' => $season->number]);
        }

        if ($episode instanceof Episode) {
            $label .= ' · '.__('issues.target_summary.episode', ['number' => $episode->number]);
        }

        $routeName = $this->safeRouteName($payload['route'] ?? null);

        return new TechnicalIssueTargetData(
            type: $targetType,
            label: $label,
            catalogTitleId: $title->id,
            seasonId: $season?->id,
            episodeId: $episode?->id,
            licensedMediaId: $media?->id,
            translationId: $translation?->id,
            featureCode: $this->safeFeature($payload['feature'] ?? null),
            routeName: $routeName,
            routePath: $this->safePath($payload['path'] ?? null),
            playerComponent: ($payload['player'] ?? null) === 'catalog-player-v1' ? 'catalog-player-v1' : null,
            sourceHealthCode: $media?->health_status?->value,
            knownDurationSeconds: $media?->duration_seconds,
            selectedQualityCode: $this->safeCode($payload['quality'] ?? null, 24),
            selectedAudioLanguage: $this->safeLanguage($payload['audio_language'] ?? null),
            selectedSubtitleLanguage: $this->safeLanguage($payload['subtitle_language'] ?? null),
        );
    }

    private function featureTarget(string $feature, ?string $routeName = null, ?string $routePath = null): TechnicalIssueTargetData
    {
        $feature = $this->safeFeature($feature) ?? 'general';
        $type = match ($feature) {
            'account' => TechnicalIssueTargetType::Account,
            'notifications' => TechnicalIssueTargetType::Notification,
            'calendar' => TechnicalIssueTargetType::Calendar,
            'search', 'filters', 'catalog' => TechnicalIssueTargetType::Search,
            'general' => TechnicalIssueTargetType::General,
            default => TechnicalIssueTargetType::Page,
        };

        return new TechnicalIssueTargetData(
            type: $type,
            label: __("issues.features.{$feature}"),
            featureCode: $feature,
            routeName: $routeName,
            routePath: $routePath,
        );
    }

    private function safeFeature(mixed $feature): ?string
    {
        return is_string($feature) && in_array($feature, config('technical-issues.feature_codes', []), true)
            ? $feature
            : null;
    }

    private function safeRouteName(mixed $route): ?string
    {
        if (! is_string($route) || mb_strlen($route) > 120 || ! Route::has($route)) {
            return null;
        }

        return Str::contains($route, ['password', 'verification', 'oauth', 'token', 'playback.source']) ? null : $route;
    }

    private function safePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || mb_strlen($path) > 240 || ! Str::startsWith($path, '/')) {
            return null;
        }

        $path = parse_url($path, PHP_URL_PATH);

        if (! is_string($path) || Str::contains($path, ['..', '\\', '/reset-password', '/email/verify', '/oauth'])) {
            return null;
        }

        return $path;
    }

    private function positiveId(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($value) ? $value : null;
    }

    private function safeCode(mixed $value, int $maximum): ?string
    {
        return is_string($value) && mb_strlen($value) <= $maximum && preg_match('/^[A-Za-z0-9._-]+$/D', $value) === 1
            ? $value
            : null;
    }

    private function safeLanguage(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $value) === 1
            ? $value
            : null;
    }
}
