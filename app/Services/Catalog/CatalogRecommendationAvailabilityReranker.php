<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Enums\PlaybackPreferenceMode;
use App\Models\LicensedMedia;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Support\Collection;

final class CatalogRecommendationAvailabilityReranker
{
    public function __construct(private readonly AccountSettingsService $settings) {}

    /**
     * @param  list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>  $candidates
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function rerank(CatalogRecommendationContext $context, array $candidates): array
    {
        if ($context->user === null || $context->type !== CatalogRecommendationType::Personalized || $candidates === []) {
            return $candidates;
        }

        $preferences = $this->settings->resolve($context->user);
        $candidateIds = array_column($candidates, 'id');
        $boosts = [];
        $mediaByTitle = LicensedMedia::query()
            ->availableTo($context->user)
            ->forAvailableReleases($context->user)
            ->withoutKnownFailures()
            ->withPlaybackLocation()
            ->whereIn('catalog_title_id', $candidateIds)
            ->when(
                $preferences->hiddenVariantKeys !== [],
                fn ($query) => $query->where(function ($query) use ($preferences): void {
                    $query
                        ->whereNull('variant_key')
                        ->orWhereNotIn('variant_key', $preferences->hiddenVariantKeys);
                }),
            )
            ->get([
                'catalog_title_id',
                'quality',
                'variant_key',
                'variant_type',
                'has_subtitles',
                'subtitle_language',
            ])
            ->groupBy('catalog_title_id');

        foreach ($mediaByTitle as $titleId => $mediaItems) {
            $titleId = (int) $titleId;
            $boost = 0;
            $boost += $preferences->preferredQuality !== null
                && $mediaItems->contains('quality', $preferences->preferredQuality)
                    ? (int) config('recommendations.availability_boosts.quality', 12)
                    : 0;
            $boost += $preferences->preferredVariant !== null
                && $mediaItems->contains('variant_key', $preferences->preferredVariant)
                    ? (int) config('recommendations.availability_boosts.variant', 12)
                    : 0;
            $boost += $preferences->fallbackVariant !== null
                && $mediaItems->contains('variant_key', $preferences->fallbackVariant)
                    ? (int) config('recommendations.availability_boosts.fallback_variant', 8)
                    : 0;
            $boost += $this->modeAvailable($mediaItems, $preferences->playbackMode)
                ? (int) config('recommendations.availability_boosts.playback_mode', 6)
                : 0;
            $boost += $preferences->preferredSubtitleLanguage !== null
                && $mediaItems->contains('subtitle_language', $preferences->preferredSubtitleLanguage)
                    ? (int) config('recommendations.availability_boosts.subtitle_language', 4)
                    : 0;
            $boost += $preferences->subtitlesEnabled && $mediaItems->contains('has_subtitles', true)
                ? (int) config('recommendations.availability_boosts.subtitles', 6)
                : 0;
            $boosts[$titleId] = $boost;
        }

        foreach ($candidates as &$candidate) {
            $candidate['score'] += max(0, (int) ($boosts[$candidate['id']] ?? 0));
        }
        unset($candidate);

        usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($right['id'] <=> $left['id']));

        return $candidates;
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     */
    private function modeAvailable(
        Collection $mediaItems,
        PlaybackPreferenceMode $mode,
    ): bool {
        return match ($mode) {
            PlaybackPreferenceMode::Automatic => false,
            PlaybackPreferenceMode::Dubbed => $mediaItems->contains('variant_type', 'voiceover'),
            PlaybackPreferenceMode::OriginalSubtitles => $mediaItems->contains(
                fn (LicensedMedia $media): bool => $media->has_subtitles
                    && in_array($media->variant_type, ['original', 'subtitles'], true),
            ),
        };
    }
}
