<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticleTranslation;
use App\Models\HelpCategoryTranslation;
use Illuminate\Routing\Router;

final readonly class HelpUrlGenerator
{
    public function __construct(private HelpLocale $locales, private Router $router) {}

    public function home(?string $routeLocale = null): string
    {
        return $this->route('help.index', [], $routeLocale);
    }

    public function search(?string $routeLocale = null, ?string $query = null): string
    {
        $parameters = is_string($query) && $query !== '' ? ['q' => $query] : [];

        return $this->route('help.search', $parameters, $routeLocale);
    }

    public function category(HelpCategoryTranslation $translation, ?string $routeLocale = null): string
    {
        return $this->route('help.categories.show', ['categorySlug' => $translation->slug], $routeLocale);
    }

    public function article(HelpArticleTranslation $translation, ?string $routeLocale = null): string
    {
        return $this->route('help.articles.show', ['articleSlug' => $translation->slug], $routeLocale);
    }

    /** @param array<string, mixed> $parameters */
    private function route(string $name, array $parameters, ?string $routeLocale): string
    {
        if ($routeLocale !== null) {
            $routeLocale = $this->locales->normalize($routeLocale);
            $localized = 'localized.'.$name;

            if ($this->router->has($localized)) {
                return route($localized, ['locale' => $routeLocale, ...$parameters]);
            }
        }

        return route($name, $parameters);
    }
}
