<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpFeedbackReason;
use App\Enums\HelpFeedbackValue;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property HelpFeedbackValue $value
 * @property HelpFeedbackReason|null $reason
 */
#[Fillable(['help_article_id', 'help_article_translation_id', 'user_id', 'actor_key', 'locale', 'value', 'reason'])]
final class HelpArticleFeedback extends Model
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['value' => HelpFeedbackValue::class, 'reason' => HelpFeedbackReason::class];
    }
}
