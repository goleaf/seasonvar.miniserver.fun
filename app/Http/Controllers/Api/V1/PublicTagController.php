<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TagModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublicTagIndexRequest;
use App\Http\Resources\Api\V1\PublicTagResource;
use App\Models\Tag;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Tags\TagQuery;
use App\Services\Tags\TagResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

final class PublicTagController extends Controller
{
    public function index(PublicTagIndexRequest $request, TagQuery $tags): AnonymousResourceCollection
    {
        $search = $request->search();
        $records = mb_strlen($search) >= 2 ? $tags->searchPublic($search, 25) : $tags->popular(25);

        return PublicTagResource::collection($records)->additional([
            'meta' => ['query' => $search, 'privacy' => 'public'],
        ]);
    }

    public function show(
        TagResolver $resolver,
        TagQuery $tags,
        CatalogTitleQuery $titles,
        Request $request,
        string $tagSlug,
    ): PublicTagResource|RedirectResponse {
        $resolved = $resolver->resolvePublic($tagSlug);
        abort_if($resolved === null, 404);

        if (! $resolved->isCanonical()) {
            $url = route('api.v1.tags.show', ['tagSlug' => $resolved->tag->slug]);
            $query = Arr::except($request->query(), ['_method', '_token']);

            return redirect()->to(
                $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                301,
            );
        }

        $tag = $tags->publicTags()
            ->whereKey($resolved->tag->id)
            ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $query->whereIn(
                'catalog_titles.id',
                $titles->visibleTo(null)->select('catalog_titles.id'),
            )])
            ->with(['aliases' => fn ($query) => $query
                ->whereIn('locale', ['und', ...$tags->contentLocales()])
                ->where('moderation_status', TagModerationStatus::Approved->value)
                ->orderBy('name')])
            ->firstOrFail();
        $related = $tags->related($tag)->map(fn (Tag $related): array => [
            'public_id' => (string) $related->public_id,
            'name' => (string) $related->name,
            'slug' => (string) $related->slug,
            'serial_count' => (int) $related->public_titles_count,
        ])->values()->all();

        return (new PublicTagResource($tag))->additional(['meta' => [
            'related' => $related,
            'privacy' => 'public',
        ]]);
    }
}
