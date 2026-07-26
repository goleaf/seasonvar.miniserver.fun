<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\PlaybackQualitySession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class PlaybackQualityContext
{
    public function captureToken(CatalogTitle $title): string
    {
        return $this->encode([
            'purpose' => 'capture',
            'title_id' => $title->id,
        ]);
    }

    public function reportToken(PlaybackQualitySession $session): string
    {
        return $this->encode([
            'purpose' => 'report',
            'request_id' => $session->request_id,
            'title_id' => $session->catalog_title_id,
            'media_id' => $session->current_media_id,
        ]);
    }

    public function captureTitleId(string $token): ?int
    {
        $payload = $this->decode($token);
        $titleId = $payload['title_id'] ?? null;

        return ($payload['purpose'] ?? null) === 'capture' && is_int($titleId) && $titleId > 0
            ? $titleId
            : null;
    }

    /** @return array{request_id: string, title_id: int, media_id: int}|null */
    public function reportPayload(string $token): ?array
    {
        $payload = $this->decode($token);
        $requestId = $payload['request_id'] ?? null;
        $titleId = $payload['title_id'] ?? null;
        $mediaId = $payload['media_id'] ?? null;

        if (($payload['purpose'] ?? null) !== 'report'
            || ! is_string($requestId)
            || ! Str::isUuid($requestId)
            || ! is_int($titleId)
            || $titleId < 1
            || ! is_int($mediaId)
            || $mediaId < 1) {
            return null;
        }

        return [
            'request_id' => $requestId,
            'title_id' => $titleId,
            'media_id' => $mediaId,
        ];
    }

    /** @param array<string, mixed> $values */
    private function encode(array $values): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'issued_at' => now()->getTimestamp(),
            ...$values,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(string $token): array
    {
        if ($token === '' || mb_strlen($token) > 4096) {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return [];
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_int($payload['issued_at'] ?? null)) {
            return [];
        }

        $ttl = max(5, (int) config('playback.quality.context_ttl_minutes', 120));
        $issuedAt = $payload['issued_at'];

        return $issuedAt >= now()->subMinutes($ttl)->getTimestamp()
            && $issuedAt <= now()->addMinute()->getTimestamp()
                ? $payload
                : [];
    }
}
