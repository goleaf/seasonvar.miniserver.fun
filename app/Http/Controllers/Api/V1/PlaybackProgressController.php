<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\PlaybackProgressInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordProgressRequest;
use App\Http\Resources\Api\V1\EpisodeProgressResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Catalog\Api\V1\PlaybackProgressRecorder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class PlaybackProgressController extends Controller
{
    public function __invoke(
        RecordProgressRequest $request,
        string $titleSlug,
        int $episode,
        PlaybackProgressRecorder $progressRecorder,
        ApiErrorResponse $errors,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $progress = $progressRecorder->record(
            $user,
            $titleSlug,
            $episode,
            new PlaybackProgressInput(
                playbackSessionToken: $request->playbackSessionToken(),
                eventSequence: $request->eventSequence(),
                positionSeconds: $request->positionSeconds(),
                reportedDurationSeconds: $request->reportedDurationSeconds(),
                ended: $request->ended(),
            ),
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
