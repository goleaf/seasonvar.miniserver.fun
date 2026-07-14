<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordProgressRequest;
use App\Http\Resources\Api\V1\EpisodeProgressResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class PlaybackProgressController extends Controller
{
    public function __invoke(
        RecordProgressRequest $request,
        string $titleSlug,
        int $episode,
        CatalogTitleQuery $titles,
        CatalogUserStateService $states,
        ApiErrorResponse $errors,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $title = $titles->visibleTo($user)->where('slug', $titleSlug)->firstOrFail();
        $progress = $states->recordProgress(
            $user,
            $title,
            $episode,
            $request->playbackSessionToken(),
            $request->eventSequence(),
            $request->positionSeconds(),
            $request->reportedDurationSeconds(),
            $request->ended(),
        );

        if ($progress === null) {
            return $errors->make(
                $request,
                'invalid_playback_progress',
                'Событие просмотра отклонено.',
                422,
            );
        }

        return (new EpisodeProgressResource($progress))
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }
}
