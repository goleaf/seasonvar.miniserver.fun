<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogTopListFilters;
use App\DTOs\CatalogTopListItem;
use App\Enums\CatalogTopListCategory;
use Illuminate\Support\Collection;

final class CatalogTopListSeoBuilder
{
    /**
     * @param  Collection<int, CatalogTopListItem>  $items
     * @return array<string, mixed>
     */
    public function build(
        CatalogTopListCategory $category,
        Collection $items,
        bool $localizedAlias,
        CatalogTopListFilters $filters,
    ): array {
        $locale = app()->currentLocale();
        $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
        $localizedCanonical = $localizedAlias && $locale !== $defaultLocale;
        $canonical = $localizedCanonical
            ? route('localized.top.show', ['locale' => $locale, 'category' => $category->value])
            : route('top.show', ['category' => $category->value]);
        $indexable = ! $filters->active()
            && $items->isNotEmpty()
            && (! $localizedAlias || $localizedCanonical);
        $title = $category->title();
        $description = $category->description();
        $itemListId = $canonical.'#top-list';
        $firstPoster = $items->first()?->title->poster_url;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $indexable
                ? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
                : 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'type' => 'website',
            'section' => __('top_lists.navigation'),
            'image' => is_string($firstPoster) && $firstPoster !== '' ? $firstPoster : null,
            'image_alt' => $items->isNotEmpty()
                ? __('catalog.seo.poster_alt', ['title' => $items->first()->title->display_title])
                : null,
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('top_lists.navigation'), 'url' => route('top.show', ['category' => CatalogTopListCategory::Movies->value])],
                ['name' => $category->label(), 'url' => $canonical],
            ],
            'alternates' => $indexable ? $this->alternates($category) : [],
            'jsonLd' => $indexable ? [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'CollectionPage',
                    'name' => $title,
                    'description' => $description,
                    'url' => $canonical,
                    'inLanguage' => $locale,
                    'mainEntity' => ['@id' => $itemListId],
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'ItemList',
                    '@id' => $itemListId,
                    'name' => $title,
                    'numberOfItems' => $items->count(),
                    'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                    'itemListElement' => $items
                        ->map(fn (CatalogTopListItem $item): array => [
                            '@type' => 'ListItem',
                            'position' => $item->rank,
                            'item' => [
                                '@type' => 'CreativeWork',
                                'name' => $item->title->display_title,
                                'url' => route('titles.show', $item->title),
                            ],
                        ])
                        ->all(),
                ],
            ] : [],
        ];
    }

    /** @return array<string, string> */
    private function alternates(CatalogTopListCategory $category): array
    {
        $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
        $defaultUrl = route('top.show', ['category' => $category->value]);
        $alternates = [];

        foreach ((array) config('catalog-collections.supported_locales', ['ru']) as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            $alternates[$locale] = $locale === $defaultLocale
                ? $defaultUrl
                : route('localized.top.show', ['locale' => $locale, 'category' => $category->value]);
        }

        $alternates['x-default'] = $defaultUrl;

        return $alternates;
    }
}
