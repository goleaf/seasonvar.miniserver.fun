<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePlaybackSessionRequest;
use App\Http\Resources\Api\V1\PlaybackSessionResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\MobilePlaybackSessionService;
use Illuminate\Http\JsonResponse;

final class PlaybackSessionController extends Controller
{
    public function __invoke(
        CreatePlaybackSessionRequest $request,
        string $titleSlug,
        MobilePlaybackSessionService $sessions,
        ApiErrorResponse $errors,
    ): JsonResponse {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $title = CatalogTitle::query()->where('slug', $titleSlug)->firstOrFail();
        $session = $sessions->create(
            $title,
            $user,
            $request->episodeId(),
            $request->mediaId(),
            $request->preferences(),
        );

        if (! $session->isReady()) {
            return $errors->make(
                $request,
                $session->status->value,
                $session->message,
                $session->status->httpStatus(),
            );
        }

        return (new PlaybackSessionResource($session))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'private, no-store');
    }
}
