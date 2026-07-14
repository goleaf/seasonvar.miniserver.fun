<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\MobilePlaybackGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class MobilePlaybackGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_user_grants_resolve_exact_identity_and_expiry(): void
    {
        $user = User::factory()->create();
        $media = LicensedMedia::factory()->create();
        $expiresAt = now()->addMinutes(5)->startOfSecond();
        $service = app(MobilePlaybackGrant::class);

        $userGrant = $service->issue($user, $media, $expiresAt);
        $resolvedUser = $service->resolve($userGrant, $media);

        $this->assertSame($user->id, $resolvedUser?->userId);
        $this->assertSame($media->id, $resolvedUser?->mediaId);
        $this->assertSame($expiresAt->getTimestamp(), $resolvedUser?->expiresAt);

        $guestGrant = $service->issue(null, $media, $expiresAt);
        $resolvedGuest = $service->resolve($guestGrant, $media);

        $this->assertNull($resolvedGuest?->userId);
        $this->assertSame($media->id, $resolvedGuest?->mediaId);
    }

    public function test_grant_rejects_invalid_tampered_expired_and_wrong_media_payloads(): void
    {
        $media = LicensedMedia::factory()->create();
        $otherMedia = LicensedMedia::factory()->create();
        $service = app(MobilePlaybackGrant::class);
        $grant = $service->issue(User::factory()->create(), $media, now()->addMinutes(5));

        $this->assertNull($service->resolve('', $media));
        $this->assertNull($service->resolve(str_repeat('x', 4097), $media));
        $this->assertNull($service->resolve(substr($grant, 0, -1).'x', $media));
        $this->assertNull($service->resolve($grant, $otherMedia));
        $this->assertNull($service->resolve(
            $service->issue(null, $media, now()->subSecond()),
            $media,
        ));
    }

    public function test_grant_rejects_unknown_versions_and_malformed_scalar_types(): void
    {
        $media = LicensedMedia::factory()->create();
        $service = app(MobilePlaybackGrant::class);

        foreach ([
            ['v' => 2, 'u' => null, 'm' => $media->id, 'x' => now()->addMinute()->timestamp],
            ['v' => 1, 'u' => 0, 'm' => $media->id, 'x' => now()->addMinute()->timestamp],
            ['v' => 1, 'u' => '1', 'm' => $media->id, 'x' => now()->addMinute()->timestamp],
            ['v' => 1, 'u' => null, 'm' => (string) $media->id, 'x' => now()->addMinute()->timestamp],
            ['v' => 1, 'u' => null, 'm' => $media->id, 'x' => 'later'],
        ] as $payload) {
            $encrypted = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

            $this->assertNull($service->resolve($encrypted, $media));
        }
    }
}
