<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CatalogAdministrationQuery
{
    /** @return LengthAwarePaginator<int, CatalogTitle> */
    public function titles(string $search, int $perPage = 20): LengthAwarePaginator
    {
        $search = str($search)->squish()->limit(80, '')->toString();

        return CatalogTitle::query()
            ->withTrashed()
            ->select(['id', 'slug', 'title', 'external_id', 'publication_status', 'is_published', 'updated_at', 'deleted_at'])
            ->withCount(['seasons', 'episodes', 'licensedMedia'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', $search.'%')
                        ->orWhere('external_id', $search)
                        ->when(ctype_digit($search), fn (Builder $query): Builder => $query->orWhereKey((int) $search));
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(max(10, min(50, $perPage)), pageName: 'catalogAdminPage');
    }

    public function title(int $titleId): CatalogTitle
    {
        return CatalogTitle::query()->withTrashed()->findOrFail($titleId);
    }

    /** @return Collection<int, Season> */
    public function seasons(CatalogTitle $title): Collection
    {
        return Season::query()
            ->withTrashed()
            ->whereBelongsTo($title)
            ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title', 'publication_status', 'audience', 'available_from', 'available_until', 'updated_at', 'deleted_at'])
            ->withCount('episodes')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get();
    }

    public function season(CatalogTitle $title, int $seasonId): Season
    {
        return Season::query()->withTrashed()->whereBelongsTo($title)->findOrFail($seasonId);
    }

    /** @return Collection<int, Episode> */
    public function episodes(CatalogTitle $title, Season $season): Collection
    {
        abort_unless($season->catalog_title_id === $title->id, 404);

        return Episode::query()
            ->withTrashed()
            ->whereBelongsTo($season)
            ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'publication_status', 'audience', 'available_from', 'available_until', 'updated_at', 'deleted_at'])
            ->withCount('licensedMedia')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get();
    }

    public function episode(CatalogTitle $title, Season $season, int $episodeId): Episode
    {
        abort_unless($season->catalog_title_id === $title->id, 404);

        return Episode::query()->withTrashed()->whereBelongsTo($season)->findOrFail($episodeId);
    }

    public function episodeInTitle(CatalogTitle $title, int $episodeId): Episode
    {
        return Episode::query()
            ->withTrashed()
            ->whereHas('season', fn (Builder $query): Builder => $query->withTrashed()->whereBelongsTo($title))
            ->findOrFail($episodeId);
    }

    /** @return Collection<int, LicensedMedia> */
    public function media(CatalogTitle $title, Episode $episode): Collection
    {
        abort_unless($episode->season()->where('catalog_title_id', $title->id)->exists(), 404);

        return LicensedMedia::query()
            ->withTrashed()
            ->whereBelongsTo($title, 'catalogTitle')
            ->whereBelongsTo($episode)
            ->select(['id', 'catalog_title_id', 'episode_id', 'title', 'storage_disk', 'quality', 'format', 'translation_name', 'status', 'audience', 'available_from', 'available_until', 'has_subtitles', 'duration_seconds', 'updated_at', 'deleted_at'])
            ->orderByDesc('status')
            ->orderByDesc('quality')
            ->orderBy('id')
            ->get();
    }

    public function mediaItem(CatalogTitle $title, Episode $episode, int $mediaId): LicensedMedia
    {
        abort_unless($episode->season()->where('catalog_title_id', $title->id)->exists(), 404);

        return LicensedMedia::query()
            ->withTrashed()
            ->whereBelongsTo($title, 'catalogTitle')
            ->whereBelongsTo($episode)
            ->findOrFail($mediaId);
    }

    public function mediaItemInTitle(CatalogTitle $title, int $mediaId): LicensedMedia
    {
        return LicensedMedia::query()
            ->withTrashed()
            ->whereBelongsTo($title, 'catalogTitle')
            ->whereHas('episode.season', fn (Builder $query): Builder => $query->withTrashed()->whereBelongsTo($title))
            ->findOrFail($mediaId);
    }

    /** @return Collection<int, Model> */
    public function selectedRelations(CatalogTitle $title, string $type): Collection
    {
        $relation = $this->relation($type);

        return $title->{$relation}()->select(['id', 'name', 'slug'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Model> */
    /** @param list<int> $selectedIds */
    public function relationOptions(string $type, string $search, array $selectedIds): Collection
    {
        $modelClass = $this->taxonomies->modelClass($this->validatedRelationType($type));
        $search = str($search)->squish()->limit(80, '')->toString();

        return $modelClass::query()
            ->select(['id', 'name', 'slug'])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('slug', 'like', $search.'%')))
            ->when($selectedIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $selectedIds))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    public function __construct(private readonly CatalogTaxonomyRegistry $taxonomies) {}

    private function relation(string $type): string
    {
        return $this->taxonomies->relationName($this->validatedRelationType($type));
    }

    private function validatedRelationType(string $type): string
    {
        abort_unless(in_array($type, ['actor', 'director', 'genre', 'country', 'translation'], true), 404);

        return $type;
    }
}
