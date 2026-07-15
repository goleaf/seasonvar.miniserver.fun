<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentTargetType;
use App\Models\CatalogCollection;
use App\Models\Comment;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\ValueObjects\CommentTarget;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

final class CommentTargetResolver
{
    public function __construct(private readonly CatalogTitleQuery $titles) {}

    public function resolve(
        CommentTargetType|string $type,
        int $targetId,
        ?User $viewer,
        ?string $interfaceLocale = null,
        bool $lock = false,
    ): CommentTarget {
        $type = is_string($type) ? CommentTargetType::tryFrom($type) : $type;

        if ($type === null || $targetId < 1 || ! in_array($type->value, config('comments.targets', []), true)) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$targetId]);
        }

        return match ($type) {
            CommentTargetType::Title => $this->title($targetId, $viewer, $lock),
            CommentTargetType::Season => $this->season($targetId, $viewer, $lock),
            CommentTargetType::Episode => $this->episode($targetId, $viewer, $lock),
            CommentTargetType::Collection => $this->collection($targetId, $viewer, $interfaceLocale, $lock),
        };
    }

    public function fromComment(Comment $comment, ?User $viewer, ?string $interfaceLocale = null): CommentTarget
    {
        return $this->resolve($comment->target_type, (int) $comment->target_id, $viewer, $interfaceLocale);
    }

    private function title(int $targetId, ?User $viewer, bool $lock): CommentTarget
    {
        $query = $this->titles
            ->visibleTo($viewer)
            ->select(['id', 'slug', 'title', 'original_title']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $title = $query->findOrFail($targetId);

        return new CommentTarget(
            type: CommentTargetType::Title,
            id: (int) $title->id,
            catalogTitleId: (int) $title->id,
            label: __('comments.targets.title_label', ['title' => $title->display_title]),
            canonicalUrl: route('titles.show', $title),
        );
    }

    private function season(int $targetId, ?User $viewer, bool $lock): CommentTarget
    {
        $query = Season::query()
            ->select(['id', 'catalog_title_id', 'number', 'kind', 'title', 'publication_status', 'audience', 'available_from', 'available_until'])
            ->availableTo($viewer)
            ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
            ->with(['catalogTitle:id,slug,title,original_title']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $season = $query->findOrFail($targetId);
        $title = $season->catalogTitle;

        return new CommentTarget(
            type: CommentTargetType::Season,
            id: (int) $season->id,
            catalogTitleId: (int) $title->id,
            label: __('comments.targets.season_label', [
                'number' => $season->number,
                'title' => $title->display_title,
            ]),
            canonicalUrl: route('titles.show', [
                'catalogTitle' => $title,
                'season' => $season->id,
            ]).'#discussion',
            seasonId: (int) $season->id,
        );
    }

    private function episode(int $targetId, ?User $viewer, bool $lock): CommentTarget
    {
        $query = Episode::query()
            ->select(['id', 'season_id', 'number', 'kind', 'title', 'publication_status', 'audience', 'available_from', 'available_until'])
            ->availableTo($viewer)
            ->whereIn('season_id', Season::query()
                ->availableTo($viewer)
                ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
                ->select('id'))
            ->with(['season:id,catalog_title_id,number,kind,title', 'season.catalogTitle:id,slug,title,original_title']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $episode = $query->findOrFail($targetId);
        $season = $episode->season;
        $title = $season->catalogTitle;

        return new CommentTarget(
            type: CommentTargetType::Episode,
            id: (int) $episode->id,
            catalogTitleId: (int) $title->id,
            label: __('comments.targets.episode_label', [
                'number' => $episode->number,
                'title' => $title->display_title,
            ]),
            canonicalUrl: route('titles.show', [
                'catalogTitle' => $title,
                'season' => $season->id,
                'episode' => $episode->id,
            ]).'#discussion',
            seasonId: (int) $season->id,
            episodeId: (int) $episode->id,
        );
    }

    private function collection(
        int $targetId,
        ?User $viewer,
        ?string $interfaceLocale,
        bool $lock,
    ): CommentTarget
    {
        $query = CatalogCollection::query()
            ->select(['id', 'slug', 'name', 'owner_id', 'type', 'visibility', 'moderation_status', 'deleted_at'])
            ->with('translations:id,catalog_collection_id,locale,name');

        if ($lock) {
            $query->lockForUpdate();
        }

        $collection = $query->findOrFail($targetId);

        if (! Gate::forUser($viewer)->allows('view', $collection) || ! Route::has('collections.show')) {
            throw (new ModelNotFoundException)->setModel(CatalogCollection::class, [$targetId]);
        }

        $localized = $interfaceLocale !== null
            && in_array($interfaceLocale, config('catalog-collections.supported_locales', []), true)
            && Route::has('localized.collections.show');

        return new CommentTarget(
            type: CommentTargetType::Collection,
            id: (int) $collection->id,
            catalogTitleId: null,
            label: __('comments.targets.collection_label', ['collection' => $collection->display_name]),
            canonicalUrl: $localized
                ? route('localized.collections.show', [
                    'locale' => $interfaceLocale,
                    'collectionSlug' => $collection->slug,
                ])
                : route('collections.show', ['collectionSlug' => $collection->slug]),
        );
    }
}
