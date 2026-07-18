<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\DTOs\Help\ResolvedHelpArticle;
use App\Models\HelpArticle;
use App\Models\HelpArticleSlug;
use App\Models\HelpArticleTranslation;
use App\Models\User;
use App\Services\Premium\PremiumAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final readonly class HelpArticleResolver
{
    public function __construct(
        private HelpLocale $locales,
        private PremiumAccessResolver $premium,
    ) {}

    public function bySlug(string $slug, string $requestedLocale, ?User $user): ?ResolvedHelpArticle
    {
        $requestedLocale = $this->locales->normalize($requestedLocale);
        $fallback = $this->locales->fallback();
        $translation = HelpArticleTranslation::query()
            ->where('locale', $requestedLocale)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();
        $legacy = false;

        if (! $translation instanceof HelpArticleTranslation) {
            $historical = HelpArticleSlug::query()
                ->where('locale', $requestedLocale)
                ->where('slug', $slug)
                ->first();
            $translation = $historical instanceof HelpArticleSlug
                ? HelpArticleTranslation::query()
                    ->where('help_article_id', $historical->help_article_id)
                    ->where('locale', $requestedLocale)
                    ->where('is_published', true)
                    ->first()
                : null;
            $legacy = $translation instanceof HelpArticleTranslation;
        }

        if (! $translation instanceof HelpArticleTranslation && $requestedLocale !== $fallback) {
            $translation = HelpArticleTranslation::query()
                ->where('locale', $fallback)
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        }

        if (! $translation instanceof HelpArticleTranslation) {
            return null;
        }

        $article = HelpArticle::query()
            ->with([
                'category.translations' => fn ($query) => $query->whereIn('locale', [$requestedLocale, $fallback]),
                'translations' => fn ($query) => $query
                    ->whereIn('locale', $this->locales->supported())
                    ->where('is_published', true),
                'replacement.translations' => fn ($query) => $query
                    ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
                    ->where('is_published', true),
            ])
            ->find($translation->help_article_id);

        if (! $article instanceof HelpArticle || ! Gate::allows('view', $article)) {
            return null;
        }

        $selected = $article->translations->firstWhere('locale', $requestedLocale)
            ?? $article->translations->firstWhere('locale', $fallback);

        if (! $selected instanceof HelpArticleTranslation) {
            return null;
        }

        return new ResolvedHelpArticle(
            article: $article,
            translation: $selected,
            requestedLocale: $requestedLocale,
            usesFallback: $selected->locale !== $requestedLocale,
            legacySlug: $legacy,
        );
    }

    public function replacementUrl(string $slug, string $requestedLocale, ?string $routeLocale, HelpUrlGenerator $urls): ?string
    {
        $requestedLocale = $this->locales->normalize($requestedLocale);
        $fallback = $this->locales->fallback();
        $translation = HelpArticleTranslation::query()
            ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
            ->where('slug', $slug)
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$requestedLocale])
            ->first();

        if (! $translation instanceof HelpArticleTranslation) {
            $history = HelpArticleSlug::query()
                ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
                ->where('slug', $slug)
                ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$requestedLocale])
                ->first();
            $translation = $history instanceof HelpArticleSlug
                ? HelpArticleTranslation::query()->where('help_article_id', $history->help_article_id)->first()
                : null;
        }

        if (! $translation instanceof HelpArticleTranslation) {
            return null;
        }

        $article = HelpArticle::query()
            ->whereKey($translation->help_article_id)
            ->where('status', 'archived')
            ->whereNotNull('replacement_article_id')
            ->with(['replacement' => fn ($query) => $query->published(), 'replacement.translations' => fn ($query) => $query
                ->whereIn('locale', array_values(array_unique([$requestedLocale, $fallback])))
                ->where('is_published', true)])
            ->first();
        $replacement = $article?->replacement?->translations?->firstWhere('locale', $requestedLocale)
            ?? $article?->replacement?->translations?->firstWhere('locale', $fallback);

        if (! $replacement instanceof HelpArticleTranslation) {
            return null;
        }

        return $urls->article($replacement, $replacement->locale === $fallback ? null : $routeLocale);
    }

    /** @param Builder<HelpArticle> $query */
    public function constrainVisible(Builder $query, ?User $user, bool $indexableOnly = false): Builder
    {
        $query->published()->where('audience', '!=', 'staff');

        if ($user === null) {
            $query->whereIn('audience', ['everyone', 'anonymous']);
        } else {
            $audiences = ['everyone', 'authenticated'];

            if ($this->premium->resolve($user)->active) {
                $audiences[] = 'premium';
            }

            $query->whereIn('audience', $audiences);
        }

        if ($indexableOnly) {
            $query->where('audience', 'everyone')->where('is_indexable', true);
        }

        return $query;
    }
}
