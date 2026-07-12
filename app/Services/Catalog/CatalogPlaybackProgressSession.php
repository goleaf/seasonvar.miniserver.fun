<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PlaybackProgressSessionData;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class CatalogPlaybackProgressSession
{
    public function issue(
        User $user,
        CatalogTitle $catalogTitle,
        Episode $episode,
        LicensedMedia $media,
    ): string {
        if ((int) $media->catalog_title_id !== $catalogTitle->id || (int) $media->episode_id !== $episode->id) {
            throw new \InvalidArgumentException('Playback progress session hierarchy mismatch.');
        }

        $ttl = max(60, min(86400, (int) config('playback.progress.session_ttl_seconds', 21600)));
        $payload = [
            's' => Str::ulid()->toBase32(),
            'u' => $user->id,
            't' => $catalogTitle->id,
            'e' => $episode->id,
            'm' => $media->id,
            'x' => now()->addSeconds($ttl)->getTimestamp(),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function resolve(
        string $token,
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
    ): ?PlaybackProgressSessionData {
        if ($token === '' || mb_strlen($token) > 2048 || $episodeId < 1) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $sessionId = $payload['s'] ?? null;
        $tokenUserId = filter_var($payload['u'] ?? null, FILTER_VALIDATE_INT);
        $tokenTitleId = filter_var($payload['t'] ?? null, FILTER_VALIDATE_INT);
        $tokenEpisodeId = filter_var($payload['e'] ?? null, FILTER_VALIDATE_INT);
        $mediaId = filter_var($payload['m'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($payload['x'] ?? null, FILTER_VALIDATE_INT);

        if (! is_string($sessionId)
            || ! Str::isUlid($sessionId)
            || $tokenUserId !== $user->id
            || $tokenTitleId !== $catalogTitle->id
            || $tokenEpisodeId !== $episodeId
            || $mediaId === false
            || $mediaId < 1
            || $expiresAt === false
            || $expiresAt < now()->getTimestamp()) {
            return null;
        }

        return new PlaybackProgressSessionData(
            id: $sessionId,
            userId: $tokenUserId,
            catalogTitleId: $tokenTitleId,
            episodeId: $tokenEpisodeId,
            mediaId: $mediaId,
            expiresAt: $expiresAt,
        );
    }
}
