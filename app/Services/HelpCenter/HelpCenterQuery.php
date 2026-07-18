<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpArticleData;
use App\DTOs\Help\HelpArticleSummaryData;
use App\DTOs\Help\HelpCategoryData;
use App\DTOs\Help\ResolvedHelpArticle;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Models\HelpContextualLink;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class HelpCenterQuery
{
    public function __construct(
        private HelpLocale $locales,
        private HelpUrlGenerator $urls,
        private HelpArticleResolver $resolver,
        private HelpArticlePresenter $presenter,
        private HelpSnapshotCache $cache,
    ) {}

    /** @return list<HelpCategoryData> */
    public function categories(string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $requestedLocale = $this->locales->normalize($requestedLocale);
        $rebuild = fn (): array => $this->categoryRows($requestedLocale, $routeLocale, $user);

        if ($user !== null) {
            return $rebuild();
        }

        return $this->cache->rememberList('categories', [
            'locale' => $requestedLocale,
            'route_locale' => $routeLocale,
            'audience' => 'guest',
        ], $rebuild,
            static fn (HelpCategoryData $category): array => $category->toCacheSnapshot(),
            static fn (array $snapshot): HelpCategoryData => HelpCategoryData::fromCacheSnapshot($snapshot),
        );
    }

    /** @return list<HelpArticleSummaryData> */
    public function featured(string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $rebuild = fn (): array => $this->articleListQuery($requestedLocale, $routeLocale, $user)
            ->where('is_featured', true)
            ->orderByDesc('editorial_priority')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(max(1, (int) config('help-center.featured_limit', 6)))
            ->get()
            ->map(fn (HelpArticle $article): ?HelpArticleSummaryData => $this->presenter->summary($article, $requestedLocale, $routeLocale))
            ->filter()
            ->values()
            ->all();

        return $user === null ? $this->cache->rememberList('featured', [
            'locale' => $requestedLocale,
            'route_locale' => $routeLocale,
            'audience' => 'guest',
        ], $rebuild,
            static fn (HelpArticleSummaryData $article): array => $article->toCacheSnapshot(),
            static fn (array $snapshot): HelpArticleSummaryData => HelpArticleSummaryData::fromCacheSnapshot($snapshot),
        ) : $rebuild();
    }

    /** @return list<HelpArticleSummaryData> */
    public function popular(string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $rebuild = fn (): array => $this->articleListQuery($requestedLocale, $routeLocale, $user)
            ->withCount([
                'feedback as recent_helpful_count' => fn (Builder $query) => $query
                    ->where('value', 'helpful')
                    ->where('created_at', '>=', now()->subDays(90)),
                'feedback as recent_feedback_count' => fn (Builder $query) => $query
                    ->where('created_at', '>=', now()->subDays(90)),
            ])
            ->orderByDesc('recent_helpful_count')
            ->orderByDesc('editorial_priority')
            ->orderByDesc('last_reviewed_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('help-center.popular_limit', 6)))
            ->get()
            ->map(fn (HelpArticle $article): ?HelpArticleSummaryData => $this->presenter->summary($article, $requestedLocale, $routeLocale))
            ->filter()
            ->values()
            ->all();

        return $user === null ? $this->cache->rememberList('popular', [
            'locale' => $requestedLocale,
            'route_locale' => $routeLocale,
            'audience' => 'guest',
        ], $rebuild,
            static fn (HelpArticleSummaryData $article): array => $article->toCacheSnapshot(),
            static fn (array $snapshot): HelpArticleSummaryData => HelpArticleSummaryData::fromCacheSnapshot($snapshot),
        ) : $rebuild();
    }

    public function categoryBySlug(string $slug, string $requestedLocale): ?HelpCategory
    {
        $fallback = $this->locales->fallback();
        $translation = HelpCategoryTranslation::query()
            ->where('locale', $requestedLocale)
            ->where('slug', $slug)
            ->first()
            ?? HelpCategoryTranslation::query()
                ->where('locale', $fallback)
                ->where('slug', $slug)
                ->first();

        if (! $translation instanceof HelpCategoryTranslation) {
            $historicalCategoryId = DB::table('help_category_slugs')
                ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
                ->where('slug', $slug)
                ->value('help_category_id');
            $translation = is_numeric($historicalCategoryId)
                ? HelpCategoryTranslation::query()
                    ->where('help_category_id', (int) $historicalCategoryId)
                    ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$requestedLocale])
                    ->first()
                : null;
        }

        return $translation instanceof HelpCategoryTranslation
            ? HelpCategory::query()
                ->visible()
                ->with(['translations' => fn ($query) => $query->whereIn('locale', array_unique([$requestedLocale, $fallback]))])
                ->find($translation->help_category_id)
            : null;
    }

    public function categoryData(HelpCategory $category, string $requestedLocale, ?string $routeLocale, ?User $user): HelpCategoryData
    {
        $fallback = $this->locales->fallback();
        $translation = $category->translations->firstWhere('locale', $requestedLocale)
            ?? $category->translations->firstWhere('locale', $fallback);
        abort_unless($translation instanceof HelpCategoryTranslation, 404);

        return new HelpCategoryData(
            id: $category->id,
            publicId: $category->public_id,
            code: $category->code,
            locale: $translation->locale,
            usesFallback: $translation->locale !== $requestedLocale,
            slug: $translation->slug,
            title: $translation->title,
            description: $translation->description,
            url: $this->urls->category($translation, $routeLocale),
            articleCount: $this->visibleArticles($user)
                ->where('help_category_id', $category->id)
                ->whereHas('translations', fn (Builder $query) => $query
                    ->whereIn('locale', array_unique([$requestedLocale, $fallback]))
                    ->where('is_published', true))
                ->count(),
        );
    }

    /** @return LengthAwarePaginator<int, HelpArticleSummaryData> */
    public function categoryArticles(HelpCategory $category, string $requestedLocale, ?string $routeLocale, ?User $user): LengthAwarePaginator
    {
        $paginator = $this->articleListQuery($requestedLocale, $routeLocale, $user)
            ->where('help_category_id', $category->id)
            ->orderByDesc('editorial_priority')
            ->orderBy('position')
            ->orderBy('id')
            ->paginate(
                max(1, (int) config('help-center.articles_per_page', 12)),
                ['*'],
                'page',
            );
        $paginator->setCollection($paginator->getCollection()
            ->map(fn (HelpArticle $article): ?HelpArticleSummaryData => $this->presenter->summary($article, $requestedLocale, $routeLocale))
            ->filter()
            ->values());

        return $paginator;
    }

    public function resolveArticle(string $slug, string $requestedLocale, ?User $user): ?ResolvedHelpArticle
    {
        return $this->resolver->bySlug($slug, $requestedLocale, $user);
    }

    public function replacementUrl(string $slug, string $requestedLocale, ?string $routeLocale): ?string
    {
        return $this->resolver->replacementUrl($slug, $requestedLocale, $routeLocale, $this->urls);
    }

    public function article(ResolvedHelpArticle $resolved, ?string $routeLocale, ?User $user): HelpArticleData
    {
        return $this->presenter->article(
            $resolved,
            $this->related($resolved->article, $resolved->requestedLocale, $routeLocale, $user),
            $routeLocale,
        );
    }

    /** @return list<HelpArticleSummaryData> */
    public function contextual(string $feature, string $context, string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $ids = HelpContextualLink::query()
            ->where('feature_code', $feature)
            ->whereIn('context_code', [$context, 'general'])
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->limit(6)
            ->pluck('help_article_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $order = $ids->flip();

        return $this->articleListQuery($requestedLocale, $routeLocale, $user)
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (HelpArticle $article): int => (int) $order->get($article->id, 999))
            ->map(fn (HelpArticle $article): ?HelpArticleSummaryData => $this->presenter->summary($article, $requestedLocale, $routeLocale))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<HelpArticleSummaryData> */
    private function related(HelpArticle $current, string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $limit = max(1, min(8, (int) config('help-center.related_limit', 4)));
        $explicit = DB::table('help_article_relations')
            ->where('help_article_id', $current->id)
            ->orderBy('position')
            ->orderBy('related_article_id')
            ->pluck('related_article_id');
        $ordered = $explicit->flip();
        $articles = $explicit->isEmpty()
            ? collect()
            : $this->articleListQuery($requestedLocale, $routeLocale, $user)
                ->whereKey($explicit)
                ->get()
                ->sortBy(fn (HelpArticle $article): int => (int) $ordered->get($article->id, 999));

        if ($articles->count() < $limit) {
            $fallback = $this->articleListQuery($requestedLocale, $routeLocale, $user)
                ->whereKeyNot($current->id)
                ->whereNotIn('id', $articles->pluck('id')->all())
                ->where(function (Builder $query) use ($current): void {
                    $query->where('feature_code', $current->feature_code->value)
                        ->orWhere('help_category_id', $current->help_category_id);
                })
                ->orderByRaw('CASE WHEN feature_code = ? THEN 0 ELSE 1 END', [$current->feature_code->value])
                ->orderByDesc('editorial_priority')
                ->orderBy('position')
                ->orderBy('id')
                ->limit($limit - $articles->count())
                ->get();
            $articles = $articles->concat($fallback);
        }

        return $articles->take($limit)
            ->map(fn (HelpArticle $article): ?HelpArticleSummaryData => $this->presenter->summary($article, $requestedLocale, $routeLocale))
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<HelpCategoryData> */
    private function categoryRows(string $requestedLocale, ?string $routeLocale, ?User $user): array
    {
        $fallback = $this->locales->fallback();
        $locales = array_values(array_unique([$requestedLocale, $fallback]));
        $categories = HelpCategory::query()
            ->visible()
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $locales)])
            ->withCount(['articles as visible_articles_count' => fn (Builder $query) => $this->resolver
                ->constrainVisible($query, $user)
                ->whereHas('translations', fn (Builder $translation) => $translation
                    ->whereIn('locale', $locales)
                    ->where('is_published', true))])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $rows = $categories->map(function (HelpCategory $category) use ($requestedLocale, $routeLocale, $fallback): ?HelpCategoryData {
            $translation = $category->translations->firstWhere('locale', $requestedLocale)
                ?? $category->translations->firstWhere('locale', $fallback);

            if (! $translation instanceof HelpCategoryTranslation) {
                return null;
            }

            return new HelpCategoryData(
                id: $category->id,
                publicId: $category->public_id,
                code: $category->code,
                locale: $translation->locale,
                usesFallback: $translation->locale !== $requestedLocale,
                slug: $translation->slug,
                title: $translation->title,
                description: $translation->description,
                url: $this->urls->category($translation, $routeLocale),
                articleCount: (int) $category->visible_articles_count,
            );
        })->filter()->keyBy('id');

        return $categories->whereNull('parent_id')->map(function (HelpCategory $category) use ($categories, $rows): ?HelpCategoryData {
            $parent = $rows->get($category->id);

            if (! $parent instanceof HelpCategoryData) {
                return null;
            }

            $children = $categories->where('parent_id', $category->id)
                ->map(fn (HelpCategory $child): ?HelpCategoryData => $rows->get($child->id))
                ->filter()
                ->values()
                ->all();

            return new HelpCategoryData(
                id: $parent->id,
                publicId: $parent->publicId,
                code: $parent->code,
                locale: $parent->locale,
                usesFallback: $parent->usesFallback,
                slug: $parent->slug,
                title: $parent->title,
                description: $parent->description,
                url: $parent->url,
                articleCount: $parent->articleCount + collect($children)->sum('articleCount'),
                children: $children,
            );
        })->filter()->values()->all();
    }

    /** @return Builder<HelpArticle> */
    private function articleListQuery(string $requestedLocale, ?string $routeLocale, ?User $user): Builder
    {
        $fallback = $this->locales->fallback();
        $locales = array_values(array_unique([$requestedLocale, $fallback]));

        return $this->visibleArticles($user)
            ->whereHas('translations', fn (Builder $query) => $query
                ->whereIn('locale', $locales)
                ->where('is_published', true))
            ->with([
                'translations' => fn ($query) => $query->whereIn('locale', $locales)->where('is_published', true),
                'category:id,code',
                'category.translations' => fn ($query) => $query->whereIn('locale', $locales),
            ]);
    }

    /** @return Builder<HelpArticle> */
    private function visibleArticles(?User $user): Builder
    {
        return $this->resolver->constrainVisible(HelpArticle::query(), $user);
    }
}
