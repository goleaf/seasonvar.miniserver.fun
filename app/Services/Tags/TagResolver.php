<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\ResolvedTag;
use App\Enums\TagModerationStatus;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagSlug;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;

final readonly class TagResolver
{
    public function __construct(
        private TagNormalizationService $normalizer,
        private TagQuery $tags,
        private CatalogTitleQuery $titles,
    ) {}

    public function resolvePublic(string $value, bool $requireVisibleTitles = true): ?ResolvedTag
    {
        $requestedValue = trim(rawurldecode($value));
        $requested = mb_strtolower($requestedValue);

        if ($requested === '' || mb_strlen($requested) > 180) {
            return null;
        }

        if (! Tag::usesCanonicalSchema()) {
            $tag = Tag::query()->where('slug', $requested)->first();

            if ($tag === null || ($requireVisibleTitles && ! $tag->catalogTitles()
                ->whereIn('catalog_titles.id', $this->titles->visibleTo(null)->select('catalog_titles.id'))
                ->exists())) {
                return null;
            }

            return new ResolvedTag($tag, 'canonical', $requestedValue);
        }

        $matchType = 'canonical';
        $tag = Tag::query()->where('slug', $requested)->first();

        if ($tag === null) {
            $history = TagSlug::query()->where('slug', $requested)->first();
            $tag = $history?->tag;
            $matchType = 'historical_slug';
        }

        if ($tag === null) {
            $activeLocale = app()->getLocale();
            $fallbackLocale = (string) config('app.fallback_locale', 'ru');
            $locales = collect([$activeLocale, $fallbackLocale, 'und'])
                ->unique()
                ->values()
                ->all();
            $alias = TagAlias::query()
                ->where('moderation_status', TagModerationStatus::Approved->value)
                ->where(function (Builder $query) use ($requested): void {
                    $query->where('slug', $requested)
                        ->orWhere(function (Builder $query) use ($requested): void {
                            $query->whereNull('slug')
                                ->where('normalized_name_hash', $this->normalizer->hash($requested));
                        });
                })
                ->where(fn (Builder $query): Builder => $query
                    ->whereNotNull('slug')
                    ->orWhereIn('locale', $locales))
                ->orderByRaw(
                    'case locale when ? then 0 when ? then 1 when ? then 2 else 3 end',
                    [$activeLocale, $fallbackLocale, 'und'],
                )
                ->orderBy('id')
                ->first();
            $tag = $alias?->tag;
            $matchType = 'alias';
        }

        $tag = $this->canonicalTarget($tag);

        if ($tag === null || ! $tag->isPubliclyEligible()) {
            return null;
        }

        if ($requireVisibleTitles && ! $tag->catalogTitles()
            ->whereIn('catalog_titles.id', $this->titles->visibleTo(null)->select('catalog_titles.id'))
            ->exists()) {
            return null;
        }

        $localized = $this->tags->publicTags()->whereKey($tag->id)->first();

        return $localized === null ? null : new ResolvedTag($localized, $matchType, $requestedValue);
    }

    private function canonicalTarget(?Tag $tag): ?Tag
    {
        $visited = [];

        for ($depth = 0; $tag !== null && $tag->merged_into_id !== null && $depth < 10; $depth++) {
            if (isset($visited[$tag->id])) {
                return null;
            }

            $visited[$tag->id] = true;
            $tag = Tag::query()->find($tag->merged_into_id);
        }

        return $tag !== null && $tag->merged_into_id === null ? $tag : null;
    }
}
