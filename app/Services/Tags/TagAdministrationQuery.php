<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\TagSynonym;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class TagAdministrationQuery
{
    /** @return LengthAwarePaginator<int, Tag> */
    public function paginate(string $search, string $moderation = '', int $perPage = 30): LengthAwarePaginator
    {
        $search = str($search)->squish()->limit(80, '')->toString();
        $term = str_replace(['%', '_'], '', $search);

        return Tag::query()
            ->withLocalizedLabel()
            ->withCount(['catalogTitles', 'translations', 'aliases', 'providerMappings'])
            ->when($moderation !== '', fn (Builder $query): Builder => $query->where('moderation_status', $moderation))
            ->when($search !== '' && $term === '', fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('tags.name', 'like', '%'.$term.'%')
                        ->orWhere('tags.slug', 'like', '%'.$term.'%')
                        ->orWhere('tags.code', 'like', '%'.$term.'%')
                        ->orWhereHas('translations', fn (Builder $query): Builder => $query->where('label', 'like', '%'.$term.'%'))
                        ->orWhereHas('aliases', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$term.'%'));
                });
            })
            ->orderByRaw('case moderation_status when ? then 0 when ? then 1 else 2 end', ['pending', 'rejected'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(max(10, min(50, $perPage)), pageName: 'tagAdminPage');
    }

    public function tag(string $publicId): ?Tag
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $publicId) !== 1) {
            return null;
        }

        $tag = Tag::query()
            ->where('public_id', $publicId)
            ->with([
                'translations' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'locale', 'label', 'short_description', 'description', 'seo_title', 'seo_description'])
                    ->orderBy('locale'),
                'aliases' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'locale', 'name', 'source', 'moderation_status'])
                    ->orderBy('locale')
                    ->orderBy('name'),
                'synonyms' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'related_tag_id', 'relationship', 'is_bidirectional', 'priority'])
                    ->with('relatedTag:id,public_id,name,slug')
                    ->orderBy('priority')
                    ->orderBy('id'),
                'inverseSynonyms' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'related_tag_id', 'relationship', 'is_bidirectional', 'priority'])
                    ->with('tag:id,public_id,name,slug')
                    ->orderBy('priority')
                    ->orderBy('id'),
                'providerMappings' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'provider', 'provider_key', 'raw_label', 'status'])
                    ->orderBy('provider')
                    ->orderBy('provider_key'),
                'historicalSlugs' => fn ($query) => $query
                    ->select(['id', 'tag_id', 'slug', 'created_at'])
                    ->orderByDesc('created_at'),
                'mergedInto:id,public_id,name,slug',
            ])
            ->withCount(['catalogTitles', 'translations', 'aliases', 'providerMappings'])
            ->first();

        if (! $tag instanceof Tag) {
            return null;
        }

        return $tag;
    }

    /** @return Collection<int, TagSynonym> */
    public function displaySynonyms(?Tag $tag): Collection
    {
        if (! $tag instanceof Tag) {
            return collect();
        }

        return $tag->synonyms
            ->concat($tag->inverseSynonyms)
            ->unique('id')
            ->sortBy([['priority', 'asc'], ['id', 'asc']])
            ->values()
            ->each(function ($synonym) use ($tag): void {
                $related = (int) $synonym->tag_id === (int) $tag->id
                    ? $synonym->relatedTag
                    : $synonym->tag;
                $synonym->setAttribute('display_related_name', $related?->name);
            });
    }

    /** @return Collection<int, Tag> */
    public function candidates(string $search, ?Tag $except = null): Collection
    {
        $search = str($search)->squish()->limit(80, '')->toString();

        if (mb_strlen($search) < 2) {
            return collect();
        }

        $term = str_replace(['%', '_'], '', $search);

        if ($term === '') {
            return collect();
        }

        return Tag::query()
            ->whereNull('merged_into_id')
            ->whereNull('archived_at')
            ->when($except !== null, fn (Builder $query): Builder => $query->whereKeyNot($except->id))
            ->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%')
                ->orWhere('code', 'like', '%'.$term.'%'))
            ->withCount(['catalogTitles', 'translations', 'aliases', 'providerMappings'])
            ->orderByDesc('catalog_titles_count')
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, CatalogTitle> */
    public function titleOptions(Tag $tag, string $search): Collection
    {
        $search = str($search)->squish()->limit(80, '')->toString();

        if (mb_strlen($search) < 2) {
            return collect();
        }

        $term = str_replace(['%', '_'], '', $search);

        if ($term === '') {
            return collect();
        }

        return CatalogTitle::query()
            ->select(['id', 'slug', 'title', 'publication_status', 'deleted_at'])
            ->where(fn (Builder $query): Builder => $query
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%')
                ->when(ctype_digit($term), fn (Builder $query): Builder => $query->orWhere('catalog_titles.id', (int) $term)))
            ->whereDoesntHave('tags', fn (Builder $query): Builder => $query->whereKey($tag->id))
            ->orderBy('title')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /** @return Collection<int, CatalogTitle> */
    public function assignedTitles(Tag $tag): Collection
    {
        return $tag->catalogTitles()
            ->withTrashed()
            ->select(['catalog_titles.id', 'catalog_titles.slug', 'catalog_titles.title', 'catalog_titles.publication_status', 'catalog_titles.deleted_at'])
            ->orderBy('catalog_titles.title')
            ->orderBy('catalog_titles.id')
            ->limit(100)
            ->get();
    }
}
