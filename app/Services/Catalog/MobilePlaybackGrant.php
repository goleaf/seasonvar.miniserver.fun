<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\MobilePlaybackGrantData;
use App\Models\LicensedMedia;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class MobilePlaybackGrant
{
    public function issue(?User $user, LicensedMedia $media, CarbonInterface $expiresAt): string
    {
        return Crypt::encryptString(json_encode([
            'v' => 1,
            'u' => $user?->id,
            'm' => $media->id,
            'x' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $grant, LicensedMedia $media): ?MobilePlaybackGrantData
    {
        if ($grant === '' || strlen($grant) > 4096) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($grant), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ! is_int($payload['m'] ?? null)
            || $payload['m'] < 1
            || $payload['m'] !== $media->id
            || ! is_int($payload['x'] ?? null)
            || $payload['x'] < now()->timestamp
            || ! array_key_exists('u', $payload)
            || ($payload['u'] !== null && (! is_int($payload['u']) || $payload['u'] < 1))) {
            return null;
        }

        return new MobilePlaybackGrantData(
            userId: $payload['u'],
            mediaId: $payload['m'],
            expiresAt: $payload['x'],
        );
    }
}
