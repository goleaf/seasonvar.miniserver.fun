<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $is_published
 * @property array<int, string>|null $link_errors
 */
#[Fillable([
    'help_article_id', 'locale', 'slug', 'title', 'summary', 'body_markdown', 'search_text',
    'keywords', 'seo_title', 'seo_description', 'callout_text', 'callout_type', 'is_published',
    'published_at', 'links_checked_at', 'link_status', 'link_errors',
])]
final class HelpArticleTranslation extends Model
{
    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'immutable_datetime',
            'links_checked_at' => 'immutable_datetime',
            'link_errors' => 'array',
        ];
    }
}
