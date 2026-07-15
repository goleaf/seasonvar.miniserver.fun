<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PersonalTagAssignmentRequest;
use App\Http\Resources\Api\V1\PersonalTagResource;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Tags\PersonalTagLibraryQuery;
use App\Services\Tags\PersonalTagService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PersonalTagAssignmentController extends Controller
{
    public function show(
        Request $request,
        CatalogTitleQuery $titles,
        PersonalTagLibraryQuery $tags,
        string $titleSlug,
    ): JsonResponse {
        $user = $this->user($request);
        $title = $this->title($titles, $user, $titleSlug);
        $assigned = $tags->assignedPublicIds($user, $title);
        $records = $tags->active($user)->keyBy('public_id');
        $ordered = collect($assigned)
            ->map(fn (string $publicId) => $records->get($publicId))
            ->filter()
            ->values();

        return PersonalTagResource::collection($ordered)
            ->additional(['meta' => ['title_slug' => $title->slug, 'privacy' => 'private']])
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(
        PersonalTagAssignmentRequest $request,
        CatalogTitleQuery $titles,
        PersonalTagService $tags,
        string $titleSlug,
    ): JsonResponse {
        $user = $this->user($request);
        $title = $this->title($titles, $user, $titleSlug);
        $result = $tags->reconcileAssignments($user, $title, $request->tagPublicIds());

        return response()->json(['data' => [
            'title_slug' => $title->slug,
            'selected_count' => count($result['selected']),
            'added_count' => count($result['added']),
            'removed_count' => count($result['removed']),
        ]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(
        Request $request,
        CatalogTitleQuery $titles,
        PersonalTagLibraryQuery $query,
        PersonalTagService $tags,
        string $titleSlug,
        string $tagPublicId,
    ): Response {
        $user = $this->user($request);
        $title = $this->title($titles, $user, $titleSlug);
        $tag = $query->owned($user, $tagPublicId);
        abort_if($tag === null, 404);
        $tags->removeAssignment($user, $tag, $title);

        return response()->noContent(headers: ['Cache-Control' => 'private, no-store']);
    }

    private function title(CatalogTitleQuery $titles, User $user, string $slug): CatalogTitle
    {
        return $titles->visibleTo($user)->where('slug', $slug)->firstOrFail();
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
