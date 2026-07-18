<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpArticleData;
use App\DTOs\Help\HelpCategoryData;
use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Support\PlainText;

final readonly class HelpSeoPresenter
{
    public function __construct(private HelpUrlGenerator $urls, private HelpLocale $locales) {}

    /** @return array<string, mixed> */
    public function home(?string $routeLocale): array
    {
        $canonicalLocale = $routeLocale === $this->locales->fallback() ? null : $routeLocale;

        return [
            'title' => __('help.home.seo_title'),
            'description' => __('help.home.seo_description'),
            'canonical' => $this->urls->home($canonicalLocale),
            'robots' => 'index, follow',
            'alternates' => collect($this->locales->supported())->mapWithKeys(fn (string $locale): array => [
                $locale => $this->urls->home($locale === $this->locales->fallback() ? null : $locale),
            ])->all(),
            'jsonLd' => [[
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => __('help.home.seo_title'),
                'description' => __('help.home.seo_description'),
                'url' => $this->urls->home($canonicalLocale),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function search(?string $routeLocale): array
    {
        $canonicalLocale = $routeLocale === $this->locales->fallback() ? null : $routeLocale;

        return [
            'title' => __('help.search.seo_title'),
            'description' => __('help.search.seo_description'),
            'canonical' => $this->urls->search($canonicalLocale),
            'robots' => 'noindex, follow',
            'social' => false,
            'alternates' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function category(HelpCategory $category, HelpCategoryData $data, ?string $routeLocale): array
    {
        $canonicalTranslation = $category->translations->firstWhere('locale', $data->locale);
        $alternates = $category->translations
            ->mapWithKeys(fn (HelpCategoryTranslation $translation): array => [
                $translation->locale => $this->urls->category(
                    $translation,
                    $translation->locale === $this->locales->fallback() ? null : $translation->locale,
                ),
            ])->all();

        return [
            'title' => $data->title,
            'description' => $data->description,
            'canonical' => ($data->usesFallback || $data->locale === $this->locales->fallback())
                && $canonicalTranslation instanceof HelpCategoryTranslation
                    ? $this->urls->category($canonicalTranslation)
                    : $data->url,
            'robots' => $data->usesFallback || $data->articleCount === 0 ? 'noindex, follow' : 'index, follow',
            'alternates' => $alternates,
            'breadcrumbs' => [
                ['name' => __('help.title'), 'url' => $this->urls->home($routeLocale)],
                ['name' => $data->title, 'url' => $data->url],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function article(HelpArticleData $article): array
    {
        $indexable = ! $article->usesFallback
            && $article->indexable
            && $article->audience->publiclyIndexable()
            && array_key_exists($article->locale, $article->alternates);
        $schema = $article->type->faqSchemaEligible() && $article->content->faqItems !== []
            ? [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => collect($article->content->faqItems)->map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => PlainText::clean($item['answer'], 5_000),
                    ],
                ])->all(),
            ]
            : [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $article->title,
                'description' => $article->summary,
                'datePublished' => $article->publishedAt,
                'dateModified' => $article->updatedAt,
                'mainEntityOfPage' => $article->canonicalUrl,
            ];

        return [
            'title' => $article->seoTitle ?: $article->title,
            'description' => $article->seoDescription ?: $article->summary,
            'canonical' => $article->canonicalUrl,
            'robots' => $indexable ? 'index, follow' : 'noindex, follow',
            'type' => 'article',
            'section' => $article->categoryTitle,
            'published_time' => $article->publishedAt,
            'updated_time' => $article->updatedAt,
            'alternates' => $indexable ? $article->alternates : [],
            'breadcrumbs' => $article->breadcrumbs,
            'jsonLd' => [$schema, $this->breadcrumbSchema($article->breadcrumbs)],
        ];
    }

    /** @param list<array{name: string, url: string}> $breadcrumbs
     * @return array<string, mixed>
     */
    private function breadcrumbSchema(array $breadcrumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumbs)->values()->map(fn (array $item, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ])->all(),
        ];
    }
}
