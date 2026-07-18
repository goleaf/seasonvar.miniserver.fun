<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use Illuminate\Support\Facades\Schema;

final class HelpCenterSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= (bool) config('help-center.enabled', true)
            && collect([
                'help_categories',
                'help_category_translations',
                'help_category_slugs',
                'help_articles',
                'help_article_translations',
                'help_article_slugs',
                'help_article_aliases',
                'help_article_relations',
                'help_article_revisions',
                'help_article_feedback',
                'help_article_reports',
                'help_contextual_links',
            ])->every(fn (string $table): bool => Schema::hasTable($table));
    }
}
