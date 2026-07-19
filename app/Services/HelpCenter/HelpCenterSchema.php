<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class HelpCenterSchema
{
    private const REQUIRED_TABLES = [
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
    ];

    private ?bool $ready = null;

    public function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        if (! (bool) config('help-center.enabled', true)) {
            return $this->ready = false;
        }

        try {
            $tables = Schema::getTableListing(schemaQualified: false);

            return $this->ready = array_diff(self::REQUIRED_TABLES, $tables) === [];
        } catch (Throwable) {
            return $this->ready = false;
        }
    }
}
