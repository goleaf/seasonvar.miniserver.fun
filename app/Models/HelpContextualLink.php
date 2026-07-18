<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpFeature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property HelpFeature $feature_code
 * @property bool $is_active
 * @property int $position
 */
#[Fillable(['feature_code', 'context_code', 'help_article_id', 'position', 'is_active'])]
final class HelpContextualLink extends Model
{
    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    protected function casts(): array
    {
        return ['feature_code' => HelpFeature::class, 'position' => 'integer', 'is_active' => 'boolean'];
    }
}
