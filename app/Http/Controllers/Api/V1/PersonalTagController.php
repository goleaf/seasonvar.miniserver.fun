<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PersonalTagIndexRequest;
use App\Http\Requests\Api\V1\PersonalTagStoreRequest;
use App\Http\Requests\Api\V1\PersonalTagUpdateRequest;
use App\Http\Resources\Api\V1\PersonalTagResource;
use App\Models\User;
use App\Services\Tags\PersonalTagLibraryQuery;
use App\Services\Tags\PersonalTagService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PersonalTagController extends Controller
{
    public function index(PersonalTagIndexRequest $request, PersonalTagLibraryQuery $tags): JsonResponse
    {
        return PersonalTagResource::collection($tags->active($this->user($request), $request->search()))
            ->additional(['meta' => ['privacy' => 'private']])
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(PersonalTagStoreRequest $request, PersonalTagService $tags): JsonResponse
    {
        return (new PersonalTagResource($tags->create($this->user($request), $request->tagData())))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(
        PersonalTagUpdateRequest $request,
        PersonalTagLibraryQuery $query,
        PersonalTagService $tags,
        string $tagPublicId,
    ): JsonResponse {
        $tag = $query->owned($this->user($request), $tagPublicId);
        abort_if($tag === null, 404);

        return (new PersonalTagResource($tags->update(
            $this->user($request),
            $tag,
            $request->tagData($tag->content_locale),
            $request->contentVersion(),
        )))->response()->header('Cache-Control', 'private, no-store');
    }

    public function destroy(
        Request $request,
        PersonalTagLibraryQuery $query,
        PersonalTagService $tags,
        string $tagPublicId,
    ): Response {
        $tag = $query->owned($this->user($request), $tagPublicId, withTrashed: true);
        abort_if($tag === null, 404);
        $tags->delete($this->user($request), $tag);

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    public function restore(
        Request $request,
        PersonalTagLibraryQuery $query,
        PersonalTagService $tags,
        string $tagPublicId,
    ): JsonResponse {
        $tag = $query->owned($this->user($request), $tagPublicId, withTrashed: true);
        abort_if($tag === null, 404);

        return (new PersonalTagResource($tags->restore($this->user($request), $tag)))
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
