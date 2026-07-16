<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogPrimaryAction;
use App\DTOs\CatalogUserStateSummary;
use App\Http\Resources\CatalogTaxonomyResource;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogTitle */
final class CatalogTitleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CatalogPrimaryAction $primaryAction */
        $primaryAction = $this->resource->getAttribute('api_primary_action');
        /** @var CatalogUserStateSummary $ratingSummary */
        $ratingSummary = $this->resource->getAttribute('api_rating_summary');
        $storedUserState = $this->resource->getAttribute('api_user_state');
        $userState = $storedUserState instanceof CatalogTitleUserState ? $storedUserState : null;
        $counts = (array) $this->resource->getAttribute('api_counts');

        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title' => $this->display_title,
            'original_title' => $this->display_original_title,
            'type' => (string) $this->type,
            'year' => $this->year === null ? null : (int) $this->year,
            'description' => $this->description,
            'poster_url' => $this->poster_url,
            'indexed_at' => $this->indexed_at?->toJSON(),
            'aliases' => $this->aliases->map(static fn ($alias): array => [
                'id' => (int) $alias->id,
                'name' => (string) $alias->name,
            ])->values()->all(),
            'ratings' => CatalogRatingResource::collection($this->ratings),
            'rating_summary' => [
                'watchlist_count' => $ratingSummary->watchlistCount,
                'rating_count' => $ratingSummary->ratingCount,
                'rating_average' => $ratingSummary->ratingAverage,
            ],
            'taxonomies' => [
                'genres' => CatalogTaxonomyResource::collection($this->genres),
                'countries' => CatalogTaxonomyResource::collection($this->countries),
                'actors' => CatalogTaxonomyResource::collection($this->actors),
                'directors' => CatalogTaxonomyResource::collection($this->directors),
                'age_ratings' => CatalogTaxonomyResource::collection($this->ageRatings),
                'translations' => CatalogTaxonomyResource::collection($this->translations),
                'statuses' => CatalogTaxonomyResource::collection($this->statuses),
                'networks' => CatalogTaxonomyResource::collection($this->networks),
                'studios' => CatalogTaxonomyResource::collection($this->studios),
                'tags' => CatalogTaxonomyResource::collection($this->tags),
            ],
            'counts' => [
                'seasons' => (int) ($counts['seasons'] ?? 0),
                'episodes' => (int) ($counts['episodes'] ?? 0),
                'media_profiles' => (int) ($counts['media_profiles'] ?? 0),
                'taxonomies' => (int) ($counts['taxonomies'] ?? 0),
            ],
            'primary_action' => [
                'type' => $primaryAction->type,
                'label' => $primaryAction->label,
                'season_id' => $primaryAction->seasonId,
                'episode_id' => $primaryAction->episodeId,
                'media_id' => $primaryAction->mediaId,
                'position_seconds' => $primaryAction->positionSeconds,
                'playable' => $primaryAction->isPlayable(),
            ],
            'user_state' => $this->when($request->user() !== null, [
                'in_watchlist' => $userState instanceof CatalogTitleUserState && $userState->in_watchlist,
                'rating' => $userState?->rating,
                'watch_status' => $userState?->watch_status?->value,
                'recommendation_feedback' => $userState?->recommendation_feedback?->value,
            ]),
            'links' => [
                'self' => url('/api/v1/titles/'.$this->slug),
                'seasons' => url('/api/v1/titles/'.$this->slug.'/seasons'),
                'recommendations' => url('/api/v1/titles/'.$this->slug.'/recommendations'),
                'reviews' => url('/api/v1/titles/'.$this->slug.'/reviews'),
                'web' => route('titles.show', $this->resource),
            ],
        ];
    }
}
