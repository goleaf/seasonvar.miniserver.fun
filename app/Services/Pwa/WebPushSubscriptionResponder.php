<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Http\Requests\Pwa\DestroyWebPushSubscriptionRequest;
use App\Http\Requests\Pwa\StoreWebPushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class WebPushSubscriptionResponder
{
    public function __construct(private readonly WebPushSubscriptionService $subscriptions) {}

    public function store(StoreWebPushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->subscriptions->register(
            $user,
            (string) $request->validated('installation_id'),
            (string) $request->validated('endpoint'),
            (string) $request->validated('locale'),
        );

        return response()->json([
            'data' => ['enabled' => true],
        ], 201);
    }

    public function destroy(DestroyWebPushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $this->subscriptions->revoke(
            $user,
            (string) $request->validated('installation_id'),
        );

        return response()->json([
            'data' => ['enabled' => false],
        ]);
    }
}
