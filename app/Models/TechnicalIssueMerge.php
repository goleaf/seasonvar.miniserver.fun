<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['duplicate_issue_id', 'canonical_issue_id', 'merged_by_id', 'created_at'])]
final class TechnicalIssueMerge extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function duplicateIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class, 'duplicate_issue_id');
    }

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function canonicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class, 'canonical_issue_id');
    }
}
