<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpArticleSummaryData;
use App\DTOs\Help\HelpSearchCriteria;
use App\Models\HelpArticle;
use App\Models\HelpArticleAlias;
use App\Models\HelpArticleTranslation;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final readonly class HelpSearchService
{
    public function __construct(
        private CatalogSearchNormalizer $normalizer,
        private HelpLocale $locales,
        private HelpArticleResolver $resolver,
        private HelpArticlePresenter $presenter,
    ) {}

    /** @return LengthAwarePaginator<int, HelpArticleSummaryData> */
    public function search(HelpSearchCriteria $criteria, ?string $routeLocale, ?User $user): LengthAwarePaginator
    {
        $items = $this->ranked($criteria->query, $criteria->locale, $routeLocale, $user, $criteria->categoryCode);
        $page = max(1, $criteria->page);
        $perPage = max(1, min(30, $criteria->perPage));

        return new Paginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'page'],
        );
    }

    /** @return list<array{id: string, type: string, label: string, meta: string, url: string}> */
    public function suggestions(string $query, string $locale, ?User $user): array
    {
        return $this->ranked($query, $locale, $locale, $user)
            ->take(max(1, min(10, (int) config('help-center.autocomplete_limit', 7))))
            ->map(fn (HelpArticleSummaryData $item): array => [
                'id' => $item->publicId,
                'type' => 'help_article',
                'label' => $item->title,
                'meta' => $item->categoryTitle,
                'url' => $item->url,
            ])->all();
    }

    /** @return Collection<int, HelpArticleSummaryData> */
    private function ranked(
        string $query,
        string $locale,
        ?string $routeLocale,
        ?User $user,
        ?string $categoryCode = null,
    ): Collection {
        $display = mb_substr($this->normalizer->display($query), 0, 120);
        $needle = $this->normalizer->key($display);

        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $locale = $this->locales->normalize($locale);
        $fallback = $this->locales->fallback();
        $locales = array_values(array_unique([$locale, $fallback]));
        $like = '%'.$needle.'%';
        $articles = $this->resolver->constrainVisible(HelpArticle::query(), $user)
            ->when($categoryCode !== null, fn (Builder $builder): Builder => $builder
                ->whereHas('category', fn (Builder $category): Builder => $category->where('code', $categoryCode)))
            ->where(function (Builder $builder) use ($locales, $like): void {
                $builder->whereHas('translations', fn (Builder $translation): Builder => $translation
                    ->whereIn('locale', $locales)
                    ->where('is_published', true)
                    ->where('search_text', 'like', $like))
                    ->orWhereHas('aliases', fn (Builder $alias): Builder => $alias
                        ->whereIn('locale', $locales)
                        ->where('normalized_alias', 'like', $like));
            })
            ->with([
                'translations' => fn ($translation) => $translation
                    ->select([
                        'id', 'help_article_id', 'locale', 'slug', 'title', 'summary',
                        'body_markdown', 'keywords', 'is_published',
                    ])
                    ->whereIn('locale', $locales)
                    ->where('is_published', true),
                'aliases' => fn ($alias) => $alias
                    ->select(['id', 'help_article_id', 'locale', 'normalized_alias', 'priority'])
                    ->whereIn('locale', $locales)
                    ->orderByDesc('priority')
                    ->orderBy('id'),
                'category:id,code',
                'category.translations' => fn ($translation) => $translation
                    ->select(['id', 'help_category_id', 'locale', 'slug', 'title'])
                    ->whereIn('locale', $locales),
            ])
            ->limit(max(1, (int) config('help-center.search_candidates', 120)))
            ->get();

        return $articles
            ->map(function (HelpArticle $article) use ($needle, $locale, $routeLocale): ?array {
                $summary = $this->presenter->summary($article, $locale, $routeLocale);

                if (! $summary instanceof HelpArticleSummaryData) {
                    return null;
                }

                $translation = $article->translations->firstWhere('locale', $locale)
                    ?? $article->translations->firstWhere('locale', $this->locales->fallback());

                if (! $translation instanceof HelpArticleTranslation) {
                    return null;
                }

                return [
                    'item' => $summary,
                    'rank' => $this->rank($article, $translation, $needle, $locale),
                    'title' => $this->normalizer->key($translation->title),
                    'id' => $article->id,
                ];
            })
            ->filter()
            ->sortBy([
                ['rank', 'asc'],
                ['title', 'asc'],
                ['id', 'asc'],
            ])
            ->pluck('item')
            ->values();
    }

    private function rank(HelpArticle $article, HelpArticleTranslation $translation, string $needle, string $locale): int
    {
        $title = $this->normalizer->key($translation->title);
        $summary = $this->normalizer->key($translation->summary);
        $keywords = $this->normalizer->key((string) $translation->keywords);
        $body = $this->normalizer->key($translation->body_markdown);
        $aliases = $article->aliases
            ->map(fn (HelpArticleAlias $alias): string => $alias->normalized_alias)
            ->filter();
        $rank = match (true) {
            $title === $needle || $aliases->contains($needle) => 0,
            str_starts_with($title, $needle) => 10,
            $aliases->contains(fn (string $alias): bool => str_starts_with($alias, $needle)) => 15,
            str_contains($title, $needle) => 20,
            str_contains($keywords, $needle) => 30,
            str_contains($summary, $needle) => 40,
            default => 50,
        };
        $rank += $translation->locale === $locale ? 0 : 4;
        $rank -= min(5, (int) floor($article->editorial_priority / 20));
        $rank -= $article->type->searchPriority();

        if ($article->last_reviewed_at?->isBefore(now()->subYear()) === true) {
            $rank += 5;
        }

        return max(0, $rank);
    }
}
