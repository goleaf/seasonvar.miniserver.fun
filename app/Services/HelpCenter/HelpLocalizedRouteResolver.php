<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticleTranslation;
use App\Models\HelpCategoryTranslation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final readonly class HelpLocalizedRouteResolver
{
    public function __construct(
        private HelpCenterSchema $schema,
        private HelpLocale $locales,
        private HelpArticleResolver $articles,
        private HelpCenterQuery $query,
        private HelpUrlGenerator $urls,
    ) {}

    public function targetFor(Request $request, string $locale): ?string
    {
        if (! $this->schema->ready() || ! in_array($locale, $this->locales->supported(), true)) {
            return null;
        }

        $name = $request->route()?->getName();

        if (! is_string($name)) {
            return null;
        }

        if (in_array($name, ['help.index', 'localized.help.index'], true)) {
            return $this->relative($this->urls->home($locale));
        }

        if (in_array($name, ['help.search', 'localized.help.search'], true)) {
            return $this->relative($this->urls->search($locale)).$this->queryString($request);
        }

        if (in_array($name, ['help.articles.show', 'localized.help.articles.show'], true)) {
            $slug = $request->route('articleSlug');
            $currentLocale = $this->locales->normalize($request->route('locale') ?: app()->getLocale());
            $user = $request->user();
            $user = $user instanceof User ? $user : null;
            $resolved = is_string($slug) ? $this->articles->bySlug($slug, $currentLocale, $user) : null;
            $translation = $resolved !== null
                ? HelpArticleTranslation::query()
                    ->where('help_article_id', $resolved->article->id)
                    ->where('is_published', true)
                    ->whereIn('locale', array_values(array_unique([$locale, $this->locales->fallback()])))
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
                    ->first()
                : null;

            return $translation instanceof HelpArticleTranslation
                ? $this->relative($this->urls->article($translation, $locale))
                : null;
        }

        if (in_array($name, ['help.categories.show', 'localized.help.categories.show'], true)) {
            $slug = $request->route('categorySlug');
            $currentLocale = $this->locales->normalize($request->route('locale') ?: app()->getLocale());
            $category = is_string($slug) ? $this->query->categoryBySlug($slug, $currentLocale) : null;
            $translation = $category !== null
                ? HelpCategoryTranslation::query()
                    ->where('help_category_id', $category->id)
                    ->whereIn('locale', array_values(array_unique([$locale, $this->locales->fallback()])))
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale])
                    ->first()
                : null;

            return $translation instanceof HelpCategoryTranslation
                ? $this->relative($this->urls->category($translation, $locale))
                : null;
        }

        return null;
    }

    private function queryString(Request $request): string
    {
        $query = Arr::only($request->query(), ['q', 'category', 'page']);

        return $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function relative(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/') ? $path : '/';
    }
}
