<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Http\Requests\Pwa\PwaActionSyncRequest;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncMutationService;
use Illuminate\Http\JsonResponse;

final class PwaActionSyncResponder
{
    public function __construct(private readonly ApiSyncMutationService $mutations) {}

    public function response(PwaActionSyncRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $results = collect($request->operations())
            ->map(fn (array $operation): array => $this->mutations->apply($user, $operation)->toArray())
            ->values()
            ->all();

        return response()->json([
            'data' => ['results' => $results],
        ]);
    }
}
