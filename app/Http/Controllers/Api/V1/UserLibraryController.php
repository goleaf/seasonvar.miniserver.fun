<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UserLibraryIndexRequest;
use App\Http\Resources\Api\V1\UserLibraryItemResource;
use App\Http\Resources\Api\V1\UserLibrarySummaryResource;
use App\Models\User;
use App\Services\Catalog\UserLibraryQuery;
use App\Services\Catalog\UserLibrarySummaryQuery;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserLibraryController extends Controller
{
    public function watchlist(
        UserLibraryIndexRequest $request,
        UserLibraryQuery $library,
    ): JsonResponse {
        return UserLibraryItemResource::collection(
            $library->watchlist($this->user($request), $request->filters()),
        )->response()->header('Cache-Control', 'private, no-store');
    }

    public function ratings(
        UserLibraryIndexRequest $request,
        UserLibraryQuery $library,
    ): JsonResponse {
        return UserLibraryItemResource::collection(
            $library->ratings($this->user($request), $request->filters()),
        )->response()->header('Cache-Control', 'private, no-store');
    }

    public function summary(Request $request, UserLibrarySummaryQuery $summaries): JsonResponse
    {
        return (new UserLibrarySummaryResource($summaries->get($this->user($request))))
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
