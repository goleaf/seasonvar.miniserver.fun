<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PwaLibrarySnapshotResponder
{
    public function __construct(private readonly CatalogTitleQuery $titles) {}

    public function response(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $visibleTitleIds = $this->titles
            ->visibleTo($user)
            ->select('catalog_titles.id');
        $limit = max(1, min(300, (int) config('pwa.offline.library_limit', 300)));
        $states = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $visibleTitleIds)
            ->where(function ($query): void {
                $query->where('in_watchlist', true)
                    ->orWhereNotNull('rating')
                    ->orWhereNotNull('watch_status');
            })
            ->join('catalog_titles', 'catalog_titles.id', '=', 'catalog_title_user_states.catalog_title_id')
            ->select([
                'catalog_title_user_states.id',
                'catalog_title_user_states.in_watchlist',
                'catalog_title_user_states.rating',
                'catalog_title_user_states.watch_status',
                'catalog_title_user_states.watchlist_version',
                'catalog_title_user_states.rating_version',
                'catalog_title_user_states.watch_status_version',
                'catalog_title_user_states.updated_at',
                'catalog_titles.slug as title_slug',
                'catalog_titles.title as title_name',
            ])
            ->selectRaw(
                "CASE WHEN catalog_titles.poster_url IS NULL OR catalog_titles.poster_url = '' THEN 0 ELSE 1 END AS has_poster",
            )
            ->latest('catalog_title_user_states.updated_at')
            ->orderByDesc('catalog_title_user_states.id')
            ->limit($limit)
            ->get();

        $items = $states->map(static fn (CatalogTitleUserState $state): array => [
            'slug' => (string) $state->getAttribute('title_slug'),
            'title' => (string) $state->getAttribute('title_name'),
            'poster_url' => ((int) $state->getAttribute('has_poster')) === 1
                ? '/pwa/posters/'.rawurlencode((string) $state->getAttribute('title_slug'))
                : null,
            'in_watchlist' => $state->in_watchlist,
            'rating' => $state->rating,
            'watch_status' => $state->watch_status?->value,
            'versions' => [
                'watchlist' => $state->watchlistVersion(),
                'rating' => $state->ratingVersion(),
                'watch_status' => $state->watchStatusVersion(),
            ],
            'updated_at' => $state->updated_at?->toJSON(),
        ])->all();

        return response()->json([
            'data' => [
                'schema_version' => 1,
                'generated_at' => now()->toJSON(),
                'items' => $items,
            ],
        ]);
    }
}
