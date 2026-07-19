<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use Illuminate\Support\Facades\Route;

final readonly class HelpLinkResolver
{
    public function __construct(private HelpLocale $locales, private HelpUrlGenerator $urls) {}

    public function resolve(string $target, string $locale): ?string
    {
        if (str_starts_with($target, 'route:')) {
            $name = substr($target, 6);

            if (! in_array($name, (array) config('help-center.allowed_route_names', []), true) || ! Route::has($name)) {
                return null;
            }

            $localized = 'localized.'.$name;

            return Route::has($localized)
                ? route($localized, ['locale' => $this->locales->normalize($locale)])
                : route($name);
        }

        if (! str_starts_with($target, 'help:')) {
            return null;
        }

        $code = substr($target, 5);
        $fallback = $this->locales->fallback();
        $article = HelpArticle::query()
            ->publiclyDiscoverable()
            ->where('code', $code)
            ->with(['translations' => fn ($query) => $query
                ->select(['id', 'help_article_id', 'locale', 'slug'])
                ->whereIn('locale', array_values(array_unique([$locale, $fallback])))
                ->where('is_published', true)])
            ->first();

        if (! $article instanceof HelpArticle) {
            return null;
        }

        $translation = $article->translations->firstWhere('locale', $locale)
            ?? $article->translations->firstWhere('locale', $fallback);

        return $translation instanceof HelpArticleTranslation
            ? $this->urls->article($translation, $locale)
            : null;
    }
}
