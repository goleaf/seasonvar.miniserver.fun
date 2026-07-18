<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['help_category_id', 'locale', 'slug', 'title', 'description', 'seo_title', 'seo_description'])]
final class HelpCategoryTranslation extends Model
{
    /** @return BelongsTo<HelpCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }
}
