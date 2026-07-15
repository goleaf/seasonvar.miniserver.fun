<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewReportCategory;
use App\Enums\ReviewReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_title_review_id',
    'reporter_id',
    'moderator_id',
    'category',
    'details',
    'status',
    'private_note',
    'deduplication_key',
    'resolved_at',
])]
final class CatalogTitleReviewReport extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => ReviewReportStatus::Open->value];

    /** @return BelongsTo<CatalogTitleReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CatalogTitleReview::class, 'catalog_title_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ReviewReportCategory::class,
            'status' => ReviewReportStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
