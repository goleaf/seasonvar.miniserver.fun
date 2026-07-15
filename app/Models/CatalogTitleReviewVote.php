<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewVoteType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['catalog_title_review_id', 'user_id', 'type'])]
final class CatalogTitleReviewVote extends Model
{
    /** @return BelongsTo<CatalogTitleReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CatalogTitleReview::class, 'catalog_title_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => ReviewVoteType::class];
    }
}
