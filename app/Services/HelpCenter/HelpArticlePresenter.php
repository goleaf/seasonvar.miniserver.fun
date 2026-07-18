<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\HelpArticleData;
use App\DTOs\Help\HelpArticleSummaryData;
use App\DTOs\Help\ResolvedHelpArticle;
use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use App\Models\HelpCategoryTranslation;

final readonly class HelpArticlePresenter
{
    public function __construct(
        private HelpUrlGenerator $urls,
        private HelpArticleContentCache $content,
        private HelpEscalationService $escalations,
        private HelpLocale $locales,
    ) {}

    public function summary(HelpArticle $article, string $requestedLocale, ?string $routeLocale): ?HelpArticleSummaryData
    {
        $fallback = $this->locales->fallback();
        $translation = $article->translations->firstWhere('locale', $requestedLocale)
            ?? $article->translations->firstWhere('locale', $fallback);
        $categoryTranslation = $article->category?->translations?->firstWhere('locale', $requestedLocale)
            ?? $article->category?->translations?->firstWhere('locale', $fallback);

        if (! $translation instanceof HelpArticleTranslation || ! $categoryTranslation instanceof HelpCategoryTranslation) {
            return null;
        }

        $effectiveRouteLocale = $routeLocale ?? ($requestedLocale !== $fallback ? $requestedLocale : null);

        return new HelpArticleSummaryData(
            id: $article->id,
            publicId: $article->public_id,
            code: $article->code,
            locale: $translation->locale,
            usesFallback: $translation->locale !== $requestedLocale,
            slug: $translation->slug,
            title: $translation->title,
            summary: $translation->summary,
            url: $this->urls->article($translation, $effectiveRouteLocale),
            type: $article->type,
            typeLabel: $article->type->label(),
            categoryCode: $article->category->code,
            categoryTitle: $categoryTranslation->title,
            categoryUrl: $this->urls->category($categoryTranslation, $effectiveRouteLocale),
            featured: $article->is_featured,
            lastReviewedLabel: $article->last_reviewed_at?->locale($translation->locale)->translatedFormat('j F Y'),
        );
    }

    /**
     * @param  list<HelpArticleSummaryData>  $related
     */
    public function article(ResolvedHelpArticle $resolved, array $related, ?string $routeLocale): HelpArticleData
    {
        $article = $resolved->article;
        $translation = $resolved->translation;
        $fallback = $this->locales->fallback();
        $categoryTranslation = $article->category->translations->firstWhere('locale', $resolved->requestedLocale)
            ?? $article->category->translations->firstWhere('locale', $fallback);
        abort_unless($categoryTranslation instanceof HelpCategoryTranslation, 404);
        $effectiveRouteLocale = $routeLocale ?? ($resolved->requestedLocale !== $fallback ? $resolved->requestedLocale : null);
        $canonicalRouteLocale = $resolved->usesFallback || $translation->locale === $fallback
            ? null
            : $effectiveRouteLocale;
        $canonical = $this->urls->article($translation, $canonicalRouteLocale);
        $categoryUrl = $this->urls->category($categoryTranslation, $effectiveRouteLocale);
        $alternates = $article->translations
            ->where('is_published', true)
            ->mapWithKeys(fn (HelpArticleTranslation $item): array => [
                $item->locale => $this->urls->article($item, $item->locale === $fallback ? null : $item->locale),
            ])->all();

        return new HelpArticleData(
            id: $article->id,
            translationId: $translation->id,
            publicId: $article->public_id,
            code: $article->code,
            locale: $translation->locale,
            requestedLocale: $resolved->requestedLocale,
            usesFallback: $resolved->usesFallback,
            slug: $translation->slug,
            title: $translation->title,
            summary: $translation->summary,
            content: $this->content->render($article, $translation),
            type: $article->type,
            audience: $article->audience,
            typeLabel: $article->type->label(),
            categoryCode: $article->category->code,
            categoryTitle: $categoryTranslation->title,
            categoryUrl: $categoryUrl,
            canonicalUrl: $canonical,
            seoTitle: $translation->seo_title,
            seoDescription: $translation->seo_description,
            calloutText: $translation->callout_text,
            calloutType: $translation->callout_type,
            publishedAt: $translation->published_at?->toAtomString(),
            updatedAt: $translation->updated_at?->toAtomString(),
            lastReviewedLabel: $article->last_reviewed_at?->locale($translation->locale)->translatedFormat('j F Y'),
            feedbackEnabled: $article->type->feedbackEnabled(),
            faqPresentation: $article->type->usesFaqPresentation(),
            tableOfContentsEnabled: ! $article->type->usesFaqPresentation(),
            indexable: $article->is_indexable,
            related: $related,
            escalations: $this->escalations->for($article, $effectiveRouteLocale),
            breadcrumbs: [
                ['name' => __('help.title'), 'url' => $this->urls->home($effectiveRouteLocale)],
                ['name' => $categoryTranslation->title, 'url' => $categoryUrl],
                ['name' => $translation->title, 'url' => $canonical],
            ],
            alternates: $alternates,
        );
    }
}
