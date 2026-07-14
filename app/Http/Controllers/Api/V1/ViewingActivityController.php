<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ViewingHistoryIndexRequest;
use App\Http\Resources\Api\V1\ContinueWatchingResource;
use App\Http\Resources\Api\V1\ViewingHistoryResource;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class ViewingActivityController extends Controller
{
    public function continueWatching(
        ViewingHistoryIndexRequest $request,
        CatalogViewingActivityQuery $activity,
    ): JsonResponse {
        return ContinueWatchingResource::collection(
            $activity->continueWatching($this->user($request), $request->limit()),
        )->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(
        ViewingHistoryIndexRequest $request,
        CatalogViewingActivityQuery $activity,
    ): JsonResponse {
        return ViewingHistoryResource::collection(
            $activity->history($this->user($request), $request->perPage(), 'page'),
        )->response()->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $episodeViewProgress): Response
    {
        $user = $this->user($request);
        $progress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereKey($episodeViewProgress)
            ->firstOrFail();

        Gate::forUser($user)->authorize('delete', $progress);
        $progress->delete();

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    public function clear(Request $request, CatalogViewingActivityService $activity): Response
    {
        $activity->clear($this->user($request));

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
