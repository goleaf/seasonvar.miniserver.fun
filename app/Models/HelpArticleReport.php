<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpReportReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property HelpReportReason $reason */
#[Fillable([
    'public_id', 'help_article_id', 'help_article_translation_id', 'reporter_id', 'reviewed_by_id',
    'actor_key', 'dedupe_key', 'locale', 'reason', 'details', 'status', 'private_note', 'reviewed_at',
])]
final class HelpArticleReport extends Model
{
    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    /** @return BelongsTo<HelpArticleTranslation, $this> */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(HelpArticleTranslation::class, 'help_article_translation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    protected function casts(): array
    {
        return ['reason' => HelpReportReason::class, 'reviewed_at' => 'immutable_datetime'];
    }
}
