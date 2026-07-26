<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PwaSessionResponder
{
    public function __construct(private readonly WebPushSubscriptionService $push) {}

    public function response(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => [
                'account_scope' => hash_hmac(
                    'sha256',
                    (string) $user->getKey(),
                    (string) config('app.key'),
                ),
                'csrf_token' => $request->session()->token(),
                'library_snapshot_url' => route('pwa.library-snapshot'),
                'action_url' => route('pwa.actions.store'),
                'push_subscription_url' => route('pwa.push-subscriptions.store'),
                'vapid_public_key' => $this->push->configured()
                    ? (string) config('pwa.push.public_key')
                    : null,
                'locale' => app()->getLocale(),
            ],
        ]);
    }
}
