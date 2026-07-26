<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PlaybackPreferencesData;
use App\DTOs\PlaybackTransitionData;
use App\DTOs\PlayerEpisodePageData;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class CatalogPlayerTransitionFactory
{
    public function __construct(
        private CatalogTitlePlaybackQuery $playback,
        private CatalogPlaybackSourceResolver $sources,
        private CatalogPlaybackProgressSession $progressSessions,
        private ExternalMediaMetadata $mediaMetadata,
    ) {}

    public function episodePage(
        CatalogTitle $title,
        ?User $user,
        Season $season,
        int $page,
        ?int $currentEpisodeId,
    ): PlayerEpisodePageData {
        $episodes = $this->playback->episodesForSeasonPage(
            $title,
            $season,
            $user,
            $page,
            perPage: 24,
        );

        return new PlayerEpisodePageData(
            seasonId: $season->id,
            seasonLabel: $this->seasonLabel($season),
            episodes: $episodes->getCollection()
                ->map(fn (Episode $episode): array => [
                    'id' => $episode->id,
                    'label' => $this->episodeLabel($episode),
                    'title' => filled($episode->title) ? (string) $episode->title : null,
                    'mediaCount' => (int) $episode->getAttribute('available_media_count'),
                    'current' => $episode->id === $currentEpisodeId,
                ])
                ->values()
                ->all(),
            page: $episodes->currentPage(),
            lastPage: $episodes->lastPage(),
        );
    }

    public function prepare(
        CatalogTitle $title,
        ?User $user,
        Episode $episode,
        ?int $requestedMediaId,
        PlaybackPreferencesData $preferences,
    ): PlaybackTransitionData {
        $episode = $this->playback->watchableEpisode($title, $user, $episode->id);

        if ($episode === null) {
            return $this->unavailable();
        }

        $requestedMedia = null;

        if ($requestedMediaId !== null) {
            $requestedMedia = $this->playback->findAvailableMedia($title, $user, $requestedMediaId);

            if ($requestedMedia === null
                || (int) $requestedMedia->catalog_title_id !== $title->id
                || (int) $requestedMedia->episode_id !== $episode->id) {
                return $this->unavailable();
            }

            if (in_array((string) $requestedMedia->variant_key, $preferences->hiddenVariantKeys, true)) {
                $requestedMedia = null;
                $requestedMediaId = null;
            }
        }

        $source = $this->sources->resolve(
            $title,
            $user,
            $episode,
            $requestedMedia?->id,
            $preferences,
        );

        if (! $source->isPlayable() || $source->mediaId === null || $source->url === null) {
            return $this->unavailable();
        }

        $selectedMedia = $this->playback->findAvailableMedia($title, $user, $source->mediaId);
        $season = $episode->season;

        if (! $selectedMedia instanceof LicensedMedia
            || ! $season instanceof Season
            || (int) $season->catalog_title_id !== $title->id
            || (int) $selectedMedia->episode_id !== $episode->id
            || (int) $selectedMedia->season_id !== $season->id) {
            return $this->unavailable();
        }

        $season->setAttribute('kind', $episode->getAttribute('season_order_kind'));
        $season->setAttribute('sort_order', $episode->getAttribute('season_order_sort'));
        $season->setAttribute('number', $episode->getAttribute('season_order_number'));
        $mediaItems = $this->playback->mediaForEpisode($title, $season, $episode, $user)
            ->reject(fn (LicensedMedia $media): bool => in_array(
                (string) $media->variant_key,
                $preferences->hiddenVariantKeys,
                true,
            ));
        $selectedMedia = $mediaItems->firstWhere('id', $selectedMedia->id);

        if (! $selectedMedia instanceof LicensedMedia) {
            return $this->unavailable();
        }

        $profile = $this->playback->mediaProfile($selectedMedia);
        $navigation = $this->playback->navigationForEpisode($title, $user, $episode);
        $noticeCode = $requestedMediaId === null
            && $preferences->variant !== null
            && ! $this->sameValue($preferences->variant, $profile['variant'])
                ? 'preferred_translation_unavailable'
                : null;
        $progressEnabled = $user !== null && $user->hasVerifiedEmail();
        $progressToken = $progressEnabled
            ? $this->progressSessions->issue($user, $title, $episode, $selectedMedia)
            : '';
        $seasonLabel = $this->seasonLabel($season);
        $episodeLabel = $this->episodeLabel($episode);
        $titleLabel = $title->display_title;

        return PlaybackTransitionData::ready(
            message: $noticeCode === null
                ? $source->message
                : __('catalog.player.transition_limited'),
            contextKey: Str::uuid()->toString(),
            source: [
                'url' => $source->url,
                'mimeType' => $source->mimeType,
                'format' => $source->format,
                'expiresAt' => $source->expiresAt,
            ],
            selection: [
                'seasonId' => $season->id,
                'episodeId' => $episode->id,
                'mediaId' => $selectedMedia->id,
                'variant' => $profile['variant'],
                'quality' => $profile['quality'],
                'format' => $profile['format'],
                'query' => $this->selectionQuery($season, $episode, $selectedMedia, $profile),
            ],
            labels: [
                'title' => $titleLabel,
                'season' => $seasonLabel,
                'episode' => $episodeLabel,
                'media' => $this->mediaLabel($selectedMedia),
                'translation' => $this->translationLabel($selectedMedia),
                'quality' => $profile['quality'] !== ''
                    ? Str::upper($profile['quality'])
                    : __('catalog.player.automatic_quality'),
                'subtitles' => $this->subtitleLabel($selectedMedia),
            ],
            translations: $this->translationOptions($mediaItems, $selectedMedia->id),
            navigation: [
                'previous' => $this->navigationItem($navigation->previous),
                'next' => $this->navigationItem($navigation->next),
            ],
            mediaSession: [
                'title' => $episodeLabel,
                'artist' => $titleLabel,
                'album' => $seasonLabel,
                'artwork' => filled($title->poster_url) ? (string) $title->poster_url : null,
            ],
            progress: [
                'enabled' => $progressEnabled,
                'token' => $progressToken,
                'sequence' => 0,
            ],
            noticeCode: $noticeCode,
        );
    }

    private function unavailable(): PlaybackTransitionData
    {
        return PlaybackTransitionData::unavailable(__('catalog.player.transition_unavailable'));
    }

    /**
     * @param  array{variant: string, quality: string, format: string}  $profile
     * @return array<string, string>
     */
    private function selectionQuery(
        Season $season,
        Episode $episode,
        LicensedMedia $media,
        array $profile,
    ): array {
        return collect([
            'season' => (string) $season->id,
            'episode' => (string) $episode->id,
            'media' => (string) $media->id,
            'variant' => $profile['variant'],
            'quality' => $profile['quality'],
            'format' => $profile['format'],
        ])
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     * @return list<array{mediaId: int, label: string, detail: string|null, active: bool, variant: string, quality: string, format: string, hasSubtitles: bool, subtitleLanguage: string|null, subtitles: string, query: array<string, string>}>
     */
    public function translationOptions(Collection $mediaItems, int $selectedMediaId): array
    {
        return $mediaItems
            ->map(function (LicensedMedia $media) use ($selectedMediaId): array {
                $profile = $this->playback->mediaProfile($media);

                return [
                    'mediaId' => $media->id,
                    'label' => $this->translationLabel($media),
                    'detail' => $this->mediaDetail($media),
                    'active' => $media->id === $selectedMediaId,
                    'variant' => $profile['variant'],
                    'quality' => $profile['quality'],
                    'format' => $profile['format'],
                    'hasSubtitles' => (bool) $media->has_subtitles,
                    'subtitleLanguage' => $this->subtitleLanguage($media),
                    'subtitles' => $this->subtitleLabel($media),
                    'query' => collect([
                        'season' => (string) $media->season_id,
                        'episode' => (string) $media->episode_id,
                        'media' => (string) $media->id,
                        'variant' => $profile['variant'],
                        'quality' => $profile['quality'],
                        'format' => $profile['format'],
                    ])
                        ->filter(fn (string $value): bool => $value !== '')
                        ->all(),
                ];
            })
            ->sortBy(fn (array $option): string => implode('|', [
                $option['active'] ? '0' : '1',
                Str::lower($option['label']),
                str_pad((string) $option['mediaId'], 20, '0', STR_PAD_LEFT),
            ]))
            ->take(24)
            ->values()
            ->all();
    }

    /** @return array{id: int, label: string, title: string|null}|null */
    private function navigationItem(?Episode $episode): ?array
    {
        return $episode instanceof Episode
            ? [
                'id' => $episode->id,
                'label' => $this->episodeLabel($episode),
                'title' => filled($episode->title) ? (string) $episode->title : null,
            ]
            : null;
    }

    private function seasonLabel(Season $season): string
    {
        $special = $season->kind === ReleaseKind::Special;

        return __(
            $special ? 'catalog.release.special_season' : 'catalog.release.season',
            ['number' => $season->number],
        );
    }

    private function episodeLabel(Episode $episode): string
    {
        $special = $episode->kind === ReleaseKind::Special;

        return __(
            $special ? 'catalog.release.special_episode' : 'catalog.release.episode',
            ['number' => $episode->number],
        );
    }

    private function mediaLabel(LicensedMedia $media): string
    {
        return collect([
            $this->translationLabel($media),
            ...$this->mediaDetailValues($media),
        ])->filter()->implode(' / ');
    }

    private function translationLabel(LicensedMedia $media): string
    {
        return match ((string) $media->variant_type) {
            'subtitles' => __('catalog.player.subtitles'),
            'original' => __('catalog.player.original'),
            'trailer' => __('catalog.player.trailer'),
            default => filled($media->variant_name)
                ? (string) $media->variant_name
                : (filled($media->translation_name)
                    ? (string) $media->translation_name
                    : __('catalog.player.voiceover')),
        };
    }

    private function subtitleLabel(LicensedMedia $media): string
    {
        if (! (bool) $media->has_subtitles) {
            return __('catalog.player.subtitles_unavailable');
        }

        $language = $this->subtitleLanguage($media);

        if ($language === null) {
            return __('catalog.player.subtitles_available');
        }

        $translationKey = 'settings.playback.subtitle_languages.'.$language;
        $languageLabel = __($translationKey);

        return __('catalog.player.subtitles_language', [
            'language' => $languageLabel !== $translationKey ? $languageLabel : Str::upper($language),
        ]);
    }

    private function subtitleLanguage(LicensedMedia $media): ?string
    {
        $language = Str::lower(trim((string) $media->subtitle_language));

        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language) === 1
            ? $language
            : null;
    }

    private function mediaDetail(LicensedMedia $media): ?string
    {
        $detail = collect($this->mediaDetailValues($media))->filter()->implode(' · ');

        return $detail !== '' ? $detail : null;
    }

    /** @return list<string|null> */
    private function mediaDetailValues(LicensedMedia $media): array
    {
        $profile = $this->playback->mediaProfile($media);
        $url = trim((string) ($media->playback_url ?: $media->path));
        $quality = $profile['quality'] !== ''
            ? $profile['quality']
            : ($url !== '' ? $this->mediaMetadata->quality($media->title, $url) : null);
        $format = $profile['format'] !== ''
            ? $profile['format']
            : ($url !== '' ? $this->mediaMetadata->format($url) : null);

        return [
            filled($quality) ? Str::upper((string) $quality) : null,
            filled($format) ? Str::upper((string) $format) : null,
        ];
    }

    private function sameValue(string $first, string $second): bool
    {
        return Str::lower($first) === Str::lower($second);
    }
}
