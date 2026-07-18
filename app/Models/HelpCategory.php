<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $is_visible
 * @property int $position
 * @property int $content_version
 */
#[Fillable(['public_id', 'code', 'parent_id', 'position', 'is_visible', 'content_version'])]
final class HelpCategory extends Model
{
    /** @return BelongsTo<HelpCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<HelpCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<HelpCategoryTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(HelpCategoryTranslation::class);
    }

    /** @return HasMany<HelpArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class);
    }

    /** @param Builder<HelpCategory> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_visible' => 'boolean',
            'content_version' => 'integer',
        ];
    }
}
