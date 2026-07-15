<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $legacy_review_id
 * @property int $canonical_review_id
 * @property int|null $legacy_catalog_title_id
 * @property string $reason
 */
#[Fillable(['legacy_review_id', 'canonical_review_id', 'legacy_catalog_title_id', 'reason'])]
final class CatalogTitleReviewAlias extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'legacy_review_id';

    /** @return BelongsTo<CatalogTitleReview, $this> */
    public function canonicalReview(): BelongsTo
    {
        return $this->belongsTo(CatalogTitleReview::class, 'canonical_review_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'legacy_review_id' => 'integer',
            'canonical_review_id' => 'integer',
            'legacy_catalog_title_id' => 'integer',
        ];
    }
}
