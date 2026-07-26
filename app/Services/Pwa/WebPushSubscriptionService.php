<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

final class WebPushSubscriptionService
{
    public const SESSION_INSTALLATION_HASH = 'pwa_push_installation_hash';

    public function __construct(private readonly VapidTokenFactory $tokens) {}

    public function configured(): bool
    {
        return (bool) config('pwa.enabled')
            && (bool) config('pwa.push.enabled')
            && $this->tokens->configured();
    }

    public function register(User $user, string $installationId, string $endpoint, string $locale): void
    {
        abort_unless($this->configured(), 503);

        $endpointHash = hash('sha256', $endpoint);
        $installationHash = hash('sha256', $installationId);

        DB::transaction(function () use ($user, $endpoint, $endpointHash, $installationHash, $locale): void {
            WebPushSubscription::query()
                ->whereBelongsTo($user)
                ->where('installation_hash', $installationHash)
                ->where('endpoint_hash', '!=', $endpointHash)
                ->delete();

            $subscription = WebPushSubscription::query()
                ->where('endpoint_hash', $endpointHash)
                ->lockForUpdate()
                ->first() ?? new WebPushSubscription;

            $subscription->fill([
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'endpoint_hash' => $endpointHash,
                'installation_hash' => $installationHash,
                'locale' => $locale,
                'failure_count' => 0,
                'last_failure_at' => null,
                'disabled_at' => null,
            ])->save();
        }, attempts: 3);

        Session::put(self::SESSION_INSTALLATION_HASH, $installationHash);
    }

    public function revoke(User $user, string $installationId): void
    {
        $installationHash = hash('sha256', $installationId);

        WebPushSubscription::query()
            ->whereBelongsTo($user)
            ->where('installation_hash', $installationHash)
            ->delete();

        if (hash_equals((string) Session::get(self::SESSION_INSTALLATION_HASH, ''), $installationHash)) {
            Session::forget(self::SESSION_INSTALLATION_HASH);
        }
    }

    public function revokeCurrent(User $user): void
    {
        $installationHash = Session::get(self::SESSION_INSTALLATION_HASH);

        if (is_string($installationHash) && preg_match('/\A[a-f0-9]{64}\z/', $installationHash) === 1) {
            WebPushSubscription::query()
                ->whereBelongsTo($user)
                ->where('installation_hash', $installationHash)
                ->delete();
        }

        Session::forget(self::SESSION_INSTALLATION_HASH);
    }
}
