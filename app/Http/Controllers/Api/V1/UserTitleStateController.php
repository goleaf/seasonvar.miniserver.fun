<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetRatingRequest;
use App\Http\Resources\Api\V1\UserTitleStateResource;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\Api\V1\UserTitleStateQuery;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserTitleStateController extends Controller
{
    public function show(
        Request $request,
        CatalogTitle $catalogTitle,
        UserTitleStateQuery $state,
    ): JsonResponse {
        return $this->response($state, $this->user($request), $catalogTitle);
    }

    public function storeWatchlist(
        Request $request,
        CatalogTitle $catalogTitle,
        CatalogUserStateService $states,
        UserTitleStateQuery $state,
    ): JsonResponse {
        $user = $this->user($request);
        $states->setWatchlist($user, $catalogTitle, true);

        return $this->response($state, $user, $catalogTitle);
    }

    public function destroyWatchlist(
        Request $request,
        CatalogTitle $catalogTitle,
        CatalogUserStateService $states,
        UserTitleStateQuery $state,
    ): JsonResponse {
        $user = $this->user($request);
        $states->setWatchlist($user, $catalogTitle, false);

        return $this->response($state, $user, $catalogTitle);
    }

    public function storeRating(
        SetRatingRequest $request,
        CatalogTitle $catalogTitle,
        CatalogUserStateService $states,
        UserTitleStateQuery $state,
    ): JsonResponse {
        $user = $this->user($request);
        $states->setRating($user, $catalogTitle, $request->rating());

        return $this->response($state, $user, $catalogTitle);
    }

    public function destroyRating(
        Request $request,
        CatalogTitle $catalogTitle,
        CatalogUserStateService $states,
        UserTitleStateQuery $state,
    ): JsonResponse {
        $user = $this->user($request);
        $states->setRating($user, $catalogTitle, null);

        return $this->response($state, $user, $catalogTitle);
    }

    private function response(
        UserTitleStateQuery $state,
        User $user,
        CatalogTitle $catalogTitle,
    ): JsonResponse {
        return (new UserTitleStateResource($state->get($user, $catalogTitle)))
            ->response()
            ->header('Cache-Control', 'private, no-store');
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
