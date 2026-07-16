<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $created_at */
#[Fillable(['technical_issue_id', 'licensed_media_id', 'actor_id', 'action', 'from_health_status', 'to_health_status', 'private_note', 'created_at'])]
final class TechnicalIssueSourceAction extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function licensedMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
