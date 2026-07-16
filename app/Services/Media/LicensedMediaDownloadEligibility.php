<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\LicensedMediaDownloadData;
use App\Enums\MediaHealthStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogEntitlementService;

final class LicensedMediaDownloadEligibility
{
    public function __construct(
        private readonly CatalogEntitlementService $entitlements,
        private readonly PlaybackSourceUrlGuard $urls,
        private readonly ExternalMediaFileType $fileTypes,
    ) {}

    public function resolve(?User $user, CatalogTitle $title, LicensedMedia $media): LicensedMediaDownloadData
    {
        if ($user === null) {
            return LicensedMediaDownloadData::unavailable('catalog.download.login_required');
        }

        $reason = $this->unavailableReason($user, $title, $media);

        if ($reason !== null) {
            return LicensedMediaDownloadData::unavailable($reason);
        }

        $extension = $this->fileTypes->trustedExtension($media);
        $target = $this->urls->verifiedDownloadUrl($this->fileTypes->effectiveUrl($media));

        if ($extension === null || $target === null) {
            return LicensedMediaDownloadData::unavailable('catalog.download.unsupported_format');
        }

        return LicensedMediaDownloadData::available(
            $target,
            $extension,
            $this->fileTypes->contentTypeForExtension($extension),
        );
    }

    public function authorizes(User $user, CatalogTitle $title, LicensedMedia $media): bool
    {
        return $this->unavailableReason($user, $title, $media) === null;
    }

    private function unavailableReason(User $user, CatalogTitle $title, LicensedMedia $media): ?string
    {
        $media->loadMissing(['catalogTitle', 'season', 'episode.season']);

        if (! $this->relationshipsMatch($title, $media)) {
            return 'catalog.download.unavailable';
        }

        foreach ($this->releases($title, $media) as $release) {
            if (! $this->entitlements->decide($user, $release)->isAllowed()) {
                return 'catalog.download.unavailable';
            }
        }

        if ($media->status === 'unavailable'
            || ! ($media->health_status ?? MediaHealthStatus::Active)->isPlayable()) {
            return 'catalog.download.remote_unavailable';
        }

        if ($this->fileTypes->isPlaylist($media)) {
            return 'catalog.download.stream_only';
        }

        if (! $this->fileTypes->isDirect($media) || $this->fileTypes->effectiveUrl($media) === null) {
            return 'catalog.download.unsupported_format';
        }

        return null;
    }

    private function relationshipsMatch(CatalogTitle $title, LicensedMedia $media): bool
    {
        if ((int) $media->catalog_title_id !== $title->id
            || ! $media->catalogTitle instanceof CatalogTitle
            || $media->catalogTitle->isNot($title)) {
            return false;
        }

        if ($media->episode_id === null) {
            return $media->season_id === null
                || ($media->season instanceof Season && (int) $media->season->catalog_title_id === $title->id);
        }

        return $media->episode instanceof Episode
            && $media->season instanceof Season
            && $media->episode->season instanceof Season
            && $media->episode->season->is($media->season)
            && (int) $media->episode->season_id === (int) $media->season_id
            && (int) $media->season->catalog_title_id === $title->id;
    }

    /** @return list<CatalogTitle|Season|Episode|LicensedMedia> */
    private function releases(CatalogTitle $title, LicensedMedia $media): array
    {
        $releases = [$title];

        if ($media->season instanceof Season) {
            $releases[] = $media->season;
        }

        if ($media->episode instanceof Episode) {
            $releases[] = $media->episode;
        }

        $releases[] = $media;

        return $releases;
    }
}
