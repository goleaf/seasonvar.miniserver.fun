<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PlaybackPreferencesData;
use App\DTOs\PlaybackSourceData;
use App\Enums\MediaHealthStatus;
use App\Enums\PlaybackAvailability;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\PlaybackSourceUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CatalogPlaybackSourceResolver
{
    public function __construct(
        private readonly PlaybackSourceUrlGuard $urls,
        private readonly ExternalMediaMetadata $mediaMetadata,
        private readonly CatalogEntitlementService $entitlements,
    ) {}

    public function resolve(
        CatalogTitle $catalogTitle,
        ?User $user,
        ?Episode $episode,
        ?int $requestedMediaId,
        PlaybackPreferencesData $preferences,
    ): PlaybackSourceData {
        $titleStatus = $this->entitlements->decide($user, $catalogTitle)->status;

        if ($titleStatus !== PlaybackAvailability::Ready) {
            return PlaybackSourceData::blocked($titleStatus);
        }

        if ($episode !== null) {
            $episode->loadMissing('season');

            if (! $episode->season instanceof Season || (int) $episode->season->catalog_title_id !== $catalogTitle->id) {
                return PlaybackSourceData::blocked(PlaybackAvailability::NotFound);
            }

            foreach ([$episode->season, $episode] as $release) {
                $status = $this->entitlements->decide($user, $release)->status;

                if ($status !== PlaybackAvailability::Ready) {
                    return PlaybackSourceData::blocked($status);
                }
            }
        }

        $query = LicensedMedia::query()
            ->with(['catalogTitle', 'season', 'episode.season'])
            ->where('catalog_title_id', $catalogTitle->id)
            ->when(
                $episode !== null,
                fn ($query) => $query->where('episode_id', $episode->id),
                fn ($query) => $query->whereNull('episode_id'),
            );

        if ($requestedMediaId !== null) {
            $query->whereKey($requestedMediaId);
        }

        $mediaItems = $query->limit(100)->get();

        if ($mediaItems->isEmpty()) {
            return PlaybackSourceData::blocked(PlaybackAvailability::NotFound);
        }

        $evaluated = $mediaItems->map(fn (LicensedMedia $media): array => [
            'media' => $media,
            'source' => $this->source($media, $user),
        ]);
        $playable = $evaluated
            ->filter(fn (array $item): bool => $item['source']['status'] === PlaybackAvailability::Ready)
            ->sortByDesc(fn (array $item): string => $this->rank($item['media'], $preferences))
            ->first();

        if (is_array($playable)) {
            return $this->safeData($playable['media'], $user, $playable['source']);
        }

        $statuses = $evaluated->pluck('source.status');
        $status = collect([
            PlaybackAvailability::AuthenticationRequired,
            PlaybackAvailability::PlanRequired,
            PlaybackAvailability::RegionBlocked,
            PlaybackAvailability::ProfileRestricted,
            PlaybackAvailability::ConcurrencyExceeded,
            PlaybackAvailability::NotYetPublished,
            PlaybackAvailability::Expired,
            PlaybackAvailability::TemporarilyUnavailable,
            PlaybackAvailability::NotFound,
        ])->first(fn (PlaybackAvailability $candidate): bool => $statuses->contains(
            fn (mixed $status): bool => $status === $candidate,
        )) ?? PlaybackAvailability::TemporarilyUnavailable;

        return PlaybackSourceData::blocked($status);
    }

    public function response(LicensedMedia $media, ?User $user): Response
    {
        $media->loadMissing(['catalogTitle', 'season', 'episode.season']);
        $source = $this->source($media, $user);

        if ($source['status'] !== PlaybackAvailability::Ready) {
            return $this->blockedResponse($source['status']);
        }

        if ($source['type'] === 'external') {
            return new RedirectResponse(
                $source['target'],
                302,
                $this->responseHeaders(),
            );
        }

        $disk = Storage::disk($source['disk']);

        if (! $disk->exists($source['target'])) {
            return $this->blockedResponse(PlaybackAvailability::TemporarilyUnavailable);
        }

        return $disk->response($source['target'], null, $this->responseHeaders());
    }

    /** @return array{status: PlaybackAvailability, type: string|null, target: string|null, disk: string|null, format: string|null, mime_type: string|null} */
    private function source(LicensedMedia $media, ?User $user): array
    {
        $status = $this->mediaStatus($media, $user);

        if ($status !== PlaybackAvailability::Ready || ! $this->relationshipsMatch($media)) {
            return $this->sourceResult($status !== PlaybackAvailability::Ready ? $status : PlaybackAvailability::NotFound);
        }

        foreach ($this->parentReleases($media) as $release) {
            $status = $this->entitlements->decide($user, $release)->status;

            if ($status !== PlaybackAvailability::Ready) {
                return $this->sourceResult($status);
            }
        }

        $raw = $media->playback_url ?: $media->path;
        $format = $this->format($media, is_string($raw) ? $raw : null);

        if ($format === null || ! in_array($format, (array) config('playback.allowed_formats', []), true)) {
            return $this->sourceResult(PlaybackAvailability::TemporarilyUnavailable);
        }

        if (is_string($raw) && parse_url($raw, PHP_URL_SCHEME) !== null) {
            $target = $this->urls->safeExternalUrl($raw);

            return $target === null
                ? $this->sourceResult(PlaybackAvailability::TemporarilyUnavailable)
                : $this->sourceResult(PlaybackAvailability::Ready, 'external', $target, null, $format);
        }

        $disk = trim((string) $media->storage_disk);
        $path = is_string($raw) ? trim($raw) : '';

        if (! in_array($disk, (array) config('playback.allowed_storage_disks', []), true) || ! $this->safePath($path)) {
            return $this->sourceResult(PlaybackAvailability::TemporarilyUnavailable);
        }

        return $this->sourceResult(PlaybackAvailability::Ready, 'storage', $path, $disk, $format);
    }

    private function mediaStatus(LicensedMedia $media, ?User $user): PlaybackAvailability
    {
        if ($media->status === 'unavailable'
            || ! ($media->health_status ?? MediaHealthStatus::Active)->isPlayable()) {
            return PlaybackAvailability::TemporarilyUnavailable;
        }

        return $this->entitlements->decide($user, $media)->status;
    }

    private function relationshipsMatch(LicensedMedia $media): bool
    {
        $title = $media->catalogTitle;

        if (! $title instanceof CatalogTitle || (int) $media->catalog_title_id !== $title->id) {
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
            && (int) $media->season->catalog_title_id === $title->id;
    }

    /** @return list<CatalogTitle|Season|Episode> */
    private function parentReleases(LicensedMedia $media): array
    {
        $releases = [$media->catalogTitle];

        if ($media->season instanceof Season) {
            $releases[] = $media->season;
        }

        if ($media->episode instanceof Episode) {
            $releases[] = $media->episode;
        }

        return $releases;
    }

    /** @param array{status: PlaybackAvailability, type: string|null, target: string|null, disk: string|null, format: string|null, mime_type: string|null} $source */
    private function safeData(LicensedMedia $media, ?User $user, array $source): PlaybackSourceData
    {
        $ttl = max(30, min(600, (int) config('playback.signed_url_ttl_seconds', 300)));
        $expiresAt = now()->addSeconds($ttl);
        $url = URL::temporarySignedRoute('playback.source', $expiresAt, [
            'licensedMedia' => $media->id,
            'viewer' => (int) ($user?->id ?? 0),
        ]);

        return new PlaybackSourceData(
            status: PlaybackAvailability::Ready,
            message: PlaybackAvailability::Ready->message(),
            mediaId: $media->id,
            url: $url,
            mimeType: $source['mime_type'],
            format: $source['format'],
            quality: $media->quality,
            variant: $media->variant_name ?: $media->translation_name,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    private function rank(LicensedMedia $media, PlaybackPreferencesData $preferences): string
    {
        $providerPriority = (int) data_get(config('playback.provider_priority', []), (string) $media->storage_disk, 0);
        $variantMatch = $this->matches($media->variant_key, $preferences->variant) ? 1 : 0;
        $audioMatch = $this->matches($media->translation_name, $preferences->audioLanguage) ? 1 : 0;
        $qualityMatch = $this->matches($media->quality, $preferences->quality) ? 1 : 0;
        $formatMatch = $this->matches($media->format, $preferences->format) ? 1 : 0;
        $knownAvailable = $media->health_status === MediaHealthStatus::Active ? 1 : 0;
        $qualityRank = $this->qualityRank($media->quality);

        return sprintf(
            '%01d%01d%01d%01d%03d%01d%03d%012d',
            $variantMatch,
            $audioMatch,
            $qualityMatch,
            $formatMatch,
            $providerPriority,
            $knownAvailable,
            $qualityRank,
            $media->id,
        );
    }

    private function qualityRank(?string $quality): int
    {
        $qualities = array_map(fn (mixed $value): string => Str::lower((string) $value), (array) config('playback.supported_qualities', []));
        $index = array_search(Str::lower((string) $quality), $qualities, true);

        return $index === false ? 0 : count($qualities) - $index;
    }

    private function matches(?string $actual, ?string $preferred): bool
    {
        return is_string($preferred) && $preferred !== '' && Str::lower((string) $actual) === Str::lower($preferred);
    }

    private function format(LicensedMedia $media, ?string $url): ?string
    {
        $format = is_string($media->format) && $media->format !== ''
            ? $media->format
            : ($url !== null ? $this->mediaMetadata->format($url) : null);

        return is_string($format) ? Str::lower($format) : null;
    }

    private function safePath(string $path): bool
    {
        return $path !== ''
            && mb_strlen($path) <= 2048
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, '/')
            && ! preg_match('~(?:^|/)\.\.(?:/|$)~', $path);
    }

    /** @return array{status: PlaybackAvailability, type: string|null, target: string|null, disk: string|null, format: string|null, mime_type: string|null} */
    private function sourceResult(
        PlaybackAvailability $status,
        ?string $type = null,
        ?string $target = null,
        ?string $disk = null,
        ?string $format = null,
    ): array {
        return [
            'status' => $status,
            'type' => $type,
            'target' => $target,
            'disk' => $disk,
            'format' => $format,
            'mime_type' => match ($format) {
                'm3u8' => 'application/x-mpegURL',
                'mp4', 'm4v' => 'video/mp4',
                'webm' => 'video/webm',
                'mov' => 'video/quicktime',
                default => null,
            },
        ];
    }

    private function blockedResponse(PlaybackAvailability $status): Response
    {
        return response($status->message(), $status->httpStatus(), $this->responseHeaders());
    }

    /** @return array<string, string> */
    private function responseHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
