<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Enums\TagModerationStatus;
use App\Models\Tag;
use App\Models\TagSynonym;
use App\Models\User;
use App\Models\UserTag;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TagQuery
{
    public function __construct(
        private TagNormalizationService $normalizer,
        private CatalogTitleQuery $titles,
        private TagSnapshotCache $cache,
    ) {}

    /** @return Builder<Tag> */
    public function publicTags(): Builder
    {
        return Tag::query()
            ->publiclyEligible()
            ->withLocalizedLabel()
            ->with([
                'translations' => fn ($query) => $query
                    ->whereIn('locale', $this->contentLocales())
                    ->select([
                        'id', 'tag_id', 'locale', 'label', 'short_description', 'description',
                        'seo_title', 'seo_description',
                    ])
                    ->orderBy('locale'),
            ]);
    }

    /** @return Collection<int, Tag> */
    public function searchPublic(string $search, ?int $limit = null): Collection
    {
        $search = $this->normalizer->display($search);
        $comparison = $this->normalizer->comparison($search);

        if (mb_strlen($comparison) < 2) {
            return collect();
        }

        $limit = max(1, min(25, $limit ?? (int) config('tags.public_search_limit', 10)));
        $term = str_replace(['%', '_'], '', $search);
        $comparisonTerm = str_replace(['%', '_'], '', $comparison);

        if (mb_strlen($comparisonTerm) < 2) {
            return collect();
        }

        $slug = Str::slug($term);
        $searchLocales = collect((array) config('tags.supported_locales', []))
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
            ->unique()
            ->values()
            ->all();
        $aliasLocales = ['und', ...$searchLocales];
        $direct = $this->publicTags()
            ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $this->constrainPublicTitles($query)])
            ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->constrainPublicTitles($query))
            ->where(function (Builder $query) use ($term, $comparisonTerm, $slug, $searchLocales, $aliasLocales): void {
                $query
                    ->where('tags.name', 'like', '%'.$term.'%')
                    ->orWhere('tags.normalized_name', 'like', '%'.$comparisonTerm.'%')
                    ->when($slug !== '', fn (Builder $query): Builder => $query->orWhere('tags.slug', 'like', '%'.$slug.'%'))
                    ->orWhereHas('translations', fn (Builder $query): Builder => $query
                        ->whereIn('locale', $searchLocales)
                        ->where('label', 'like', '%'.$term.'%'))
                    ->orWhereHas('aliases', fn (Builder $query): Builder => $query
                        ->whereIn('locale', $aliasLocales)
                        ->where('moderation_status', TagModerationStatus::Approved->value)
                        ->where(fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$term.'%')
                            ->orWhere('normalized_name', 'like', '%'.$comparisonTerm.'%')
                            ->when($slug !== '', fn (Builder $query): Builder => $query->orWhere('slug', 'like', '%'.$slug.'%'))));
            })
            ->orderByRaw('case when tags.normalized_name_hash = ? then 0 else 1 end', [$this->normalizer->hash($comparison)])
            ->orderByDesc('public_titles_count')
            ->orderBy('tags.name')
            ->orderBy('tags.id')
            ->limit($limit)
            ->get();

        if ($direct->isEmpty() || $direct->count() >= $limit) {
            return $direct->values();
        }

        $synonymIds = TagSynonym::query()
            ->whereIn('tag_id', $direct->modelKeys())
            ->orWhere(function (Builder $query) use ($direct): void {
                $query->where('is_bidirectional', true)->whereIn('related_tag_id', $direct->modelKeys());
            })
            ->orderBy('priority')
            ->limit((int) config('tags.synonym_expansion_limit', 12))
            ->get(['tag_id', 'related_tag_id', 'is_bidirectional'])
            ->flatMap(function (TagSynonym $synonym) use ($direct): array {
                $ids = $direct->modelKeys();

                if (in_array((int) $synonym->tag_id, $ids, true)) {
                    return [(int) $synonym->related_tag_id];
                }

                return $synonym->is_bidirectional ? [(int) $synonym->tag_id] : [];
            })
            ->diff($direct->modelKeys())
            ->unique()
            ->take($limit - $direct->count())
            ->values();

        if ($synonymIds->isEmpty()) {
            return $direct->values();
        }

        $expanded = $this->publicTags()
            ->whereKey($synonymIds)
            ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $this->constrainPublicTitles($query)])
            ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->constrainPublicTitles($query))
            ->get()
            ->sortBy(fn (Tag $tag): int => (int) $synonymIds->search((int) $tag->id));

        return $direct->concat($expanded)->unique('id')->take($limit)->values();
    }

    /** @return Collection<int, Tag> */
    public function popular(?int $limit = null): Collection
    {
        $limit = max(1, min(50, $limit ?? (int) config('tags.popular_limit', 24)));
        $rows = $this->cache->remember('popular', [
            'locale' => app()->getLocale(),
            'fallback' => config('app.fallback_locale'),
            'limit' => $limit,
            'audience' => 'public',
        ], function () use ($limit): array {
            return $this->publicTags()
                ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $this->constrainPublicTitles($query)])
                ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->constrainPublicTitles($query))
                ->orderByDesc('public_titles_count')
                ->orderBy('tags.name')
                ->orderBy('tags.id')
                ->limit($limit)
                ->get()
                ->map(fn (Tag $tag): array => $this->summary($tag))
                ->all();
        });

        return collect($rows)->map(fn (array $row): Tag => $this->fromSummary($row))->values();
    }

    /** @return Collection<int, Tag> */
    public function related(Tag $tag, ?int $limit = null): Collection
    {
        $limit = max(1, min(24, $limit ?? (int) config('tags.related_limit', 12)));
        $rows = $this->cache->remember('related', [
            'tag' => (string) $tag->public_id,
            'locale' => app()->getLocale(),
            'fallback' => config('app.fallback_locale'),
            'limit' => $limit,
            'audience' => 'public',
        ], function () use ($tag, $limit): array {
            $visibleTitles = $this->titles->visibleTo(null)->select('catalog_titles.id');
            $cooccurrence = DB::table('catalog_title_tag as selected_tag')
                ->join('catalog_title_tag as related_tag', 'related_tag.catalog_title_id', '=', 'selected_tag.catalog_title_id')
                ->joinSub($visibleTitles, 'visible_tag_titles', 'visible_tag_titles.id', '=', 'selected_tag.catalog_title_id')
                ->where('selected_tag.tag_id', $tag->id)
                ->whereColumn('related_tag.tag_id', '!=', 'selected_tag.tag_id')
                ->whereIn('related_tag.tag_id', Tag::query()->publiclyEligible()->select('id'))
                ->select('related_tag.tag_id')
                ->selectRaw('count(distinct selected_tag.catalog_title_id) as shared_titles_count')
                ->groupBy('related_tag.tag_id')
                ->orderByDesc('shared_titles_count')
                ->limit($limit * 2)
                ->pluck('shared_titles_count', 'related_tag.tag_id');
            $editorial = TagSynonym::query()
                ->where('tag_id', $tag->id)
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('related_tag_id', $tag->id)
                    ->where('is_bidirectional', true))
                ->orderBy('priority')
                ->limit($limit * 2)
                ->get()
                ->toBase()
                ->map(fn (TagSynonym $synonym): int => (int) ($synonym->tag_id === $tag->id
                    ? $synonym->related_tag_id
                    : $synonym->tag_id));
            $ids = $editorial
                ->concat($cooccurrence->keys()->map(fn (mixed $id): int => (int) $id))
                ->reject(fn (int $id): bool => $id === (int) $tag->id)
                ->unique()
                ->take($limit)
                ->values();
            $records = $ids->isEmpty()
                ? collect()
                : $this->publicTags()
                    ->whereKey($ids)
                    ->withCount(['catalogTitles as public_titles_count' => fn (Builder $query): Builder => $this->constrainPublicTitles($query)])
                    ->whereHas('catalogTitles', fn (Builder $query): Builder => $this->constrainPublicTitles($query))
                    ->get()
                    ->sortBy(fn (Tag $record): int => (int) $ids->search((int) $record->id));

            return $records->map(fn (Tag $record): array => [
                ...$this->summary($record),
                'shared_titles_count' => (int) ($cooccurrence->get($record->id) ?? 0),
            ])->all();
        });

        return collect($rows)->map(function (array $row): Tag {
            $tag = $this->fromSummary($row);
            $tag->setAttribute('shared_titles_count', (int) ($row['shared_titles_count'] ?? 0));

            return $tag;
        })->values();
    }

    /** @return Collection<int, UserTag> */
    public function personal(User $user, string $search = ''): Collection
    {
        $search = $this->normalizer->comparison($search);
        $term = str_replace(['%', '_'], '', $search);

        if ($search !== '' && $term === '') {
            return collect();
        }

        return UserTag::query()
            ->ownedBy($user)
            ->select(['id', 'public_id', 'user_id', 'name', 'description', 'content_locale', 'content_version', 'updated_at'])
            ->withCount('catalogTitles')
            ->when($term !== '', fn (Builder $query): Builder => $query->where('normalized_name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(250)
            ->get();
    }

    /** @return list<string> */
    public function contentLocales(): array
    {
        return collect([app()->getLocale(), (string) config('app.fallback_locale', 'ru')])
            ->filter(fn (string $locale): bool => in_array($locale, config('tags.supported_locales', []), true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function summary(Tag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'public_id' => (string) $tag->public_id,
            'name' => (string) $tag->name,
            'slug' => (string) $tag->slug,
            'type' => $tag->type->value,
            'visibility' => $tag->visibility->value,
            'moderation_status' => $tag->moderation_status->value,
            'source' => $tag->source->value,
            'public_titles_count' => (int) ($tag->public_titles_count ?? 0),
        ];
    }

    /** @param array<string, mixed> $row */
    private function fromSummary(array $row): Tag
    {
        $tag = new Tag;
        $tag->setRawAttributes($row, true);

        return $tag;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function constrainPublicTitles(Builder $query): Builder
    {
        return $query->whereIn(
            'catalog_titles.id',
            $this->titles->visibleTo(null)->select('catalog_titles.id'),
        );
    }
}
