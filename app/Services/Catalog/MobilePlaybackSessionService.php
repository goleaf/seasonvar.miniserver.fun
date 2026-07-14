<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\MobilePlaybackSessionData;
use App\DTOs\PlaybackPreferencesData;
use App\Enums\PlaybackAvailability;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\URL;

final readonly class MobilePlaybackSessionService
{
    public function __construct(
        private CatalogTitlePlaybackQuery $playback,
        private CatalogPlaybackSourceResolver $sources,
        private CatalogEntitlementService $entitlements,
        private MobilePlaybackGrant $grants,
        private CatalogPlaybackProgressSession $progressSessions,
    ) {}

    public function create(
        CatalogTitle $title,
        ?User $user,
        ?int $episodeId,
        ?int $mediaId,
        PlaybackPreferencesData $preferences,
    ): MobilePlaybackSessionData {
        $titleStatus = $this->entitlements->decide($user, $title)->status;

        if ($titleStatus !== PlaybackAvailability::Ready) {
            return MobilePlaybackSessionData::blocked($titleStatus);
        }

        $title = $this->playback->visibleTitle($title->id, $user);
        $episode = $episodeId === null ? null : $this->playback->watchableEpisode($title, $user, $episodeId);

        if ($episodeId !== null && ! $episode instanceof Episode) {
            return MobilePlaybackSessionData::blocked(PlaybackAvailability::NotFound);
        }

        $source = $this->sources->resolve($title, $user, $episode, $mediaId, $preferences);

        if (! $source->isPlayable() || $source->mediaId === null) {
            return MobilePlaybackSessionData::blocked($source->status);
        }

        $media = $this->playback->findAvailableMedia($title, $user, $source->mediaId);

        if ($media === null
            || ($episode !== null && (int) $media->episode_id !== $episode->id)
            || ($episode === null && $media->episode_id !== null)) {
            return MobilePlaybackSessionData::blocked(PlaybackAvailability::NotFound);
        }

        $ttl = max(30, min(600, (int) config('playback.signed_url_ttl_seconds', 300)));
        $expiresAt = now()->addSeconds($ttl);
        $grant = $this->grants->issue($user, $media, $expiresAt);
        $playbackUrl = URL::temporarySignedRoute('api.v1.playback.source', $expiresAt, [
            'licensedMedia' => $media->id,
            'grant' => $grant,
        ]);
        $navigation = null;
        $progressToken = null;

        if ($episode !== null) {
            $season = Season::query()
                ->availableTo($user)
                ->where('catalog_title_id', $title->id)
                ->find($episode->season_id);

            if (! $season instanceof Season) {
                return MobilePlaybackSessionData::blocked(PlaybackAvailability::NotFound);
            }

            $episodes = $this->playback->episodesForSeason($title, $season, $user);
            $selectedEpisode = $episodes->firstWhere('id', $episode->id);

            if (! $selectedEpisode instanceof Episode) {
                return MobilePlaybackSessionData::blocked(PlaybackAvailability::NotFound);
            }

            $episode = $selectedEpisode;
            $navigation = $this->playback->episodeNavigation(
                $title,
                $season,
                $user,
                $episode,
                $episodes,
                $this->playback->seasonSummaries($title, $user),
            );

            if ($user?->hasVerifiedEmail() === true) {
                $progressToken = $this->progressSessions->issue($user, $title, $episode, $media);
            }
        }

        return new MobilePlaybackSessionData(
            status: PlaybackAvailability::Ready,
            message: PlaybackAvailability::Ready->message(),
            title: $title,
            episode: $episode,
            media: $media,
            playbackUrl: $playbackUrl,
            mimeType: $source->mimeType,
            format: $source->format,
            quality: $source->quality,
            variant: $source->variant,
            expiresAt: $expiresAt,
            navigation: $navigation,
            progressSessionToken: $progressToken,
        );
    }
}
