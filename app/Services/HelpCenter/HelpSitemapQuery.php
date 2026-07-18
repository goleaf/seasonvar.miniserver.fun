<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticleTranslation;
use App\Models\HelpCategoryTranslation;
use Illuminate\Database\Eloquent\Builder;

final readonly class HelpSitemapQuery
{
    public function __construct(private HelpCenterSchema $schema) {}

    public function available(): bool
    {
        return (bool) config('help-center.enabled', true) && $this->schema->ready();
    }

    /** @return Builder<HelpArticleTranslation> */
    public function articles(): Builder
    {
        return HelpArticleTranslation::query()
            ->where('is_published', true)
            ->whereIn('locale', config('help-center.supported_locales', ['ru']))
            ->whereHas('article', fn (Builder $query): Builder => $query
                ->published()
                ->where('audience', 'everyone')
                ->where('is_indexable', true))
            ->with('article:id,updated_at,last_reviewed_at')
            ->orderBy('id');
    }

    /** @return Builder<HelpCategoryTranslation> */
    public function categories(): Builder
    {
        return HelpCategoryTranslation::query()
            ->whereIn('locale', config('help-center.supported_locales', ['ru']))
            ->whereHas('category', fn (Builder $query): Builder => $query
                ->visible()
                ->whereHas('articles', fn (Builder $articles): Builder => $articles
                    ->published()
                    ->where('audience', 'everyone')
                    ->where('is_indexable', true)
                    ->whereHas('translations', fn (Builder $translations): Builder => $translations
                        ->whereColumn('help_article_translations.locale', 'help_category_translations.locale')
                        ->where('is_published', true))))
            ->with('category:id,updated_at')
            ->orderBy('id');
    }
}
