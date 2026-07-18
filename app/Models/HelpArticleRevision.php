<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpPublicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property HelpPublicationStatus $article_status
 * @property bool $translation_published
 * @property int $revision
 */
#[Fillable([
    'public_id', 'help_article_id', 'editor_id', 'locale', 'revision', 'article_status',
    'translation_published', 'slug', 'title', 'summary', 'body_markdown', 'keywords',
    'seo_title', 'seo_description', 'callout_text', 'callout_type', 'change_note',
])]
final class HelpArticleRevision extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    protected function casts(): array
    {
        return [
            'article_status' => HelpPublicationStatus::class,
            'translation_published' => 'boolean',
            'revision' => 'integer',
        ];
    }
}
