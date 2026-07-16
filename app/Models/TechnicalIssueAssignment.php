<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $ended_at */
#[Fillable(['technical_issue_id', 'assigned_by_id', 'assignee_id', 'support_team', 'ended_at'])]
final class TechnicalIssueAssignment extends Model
{
    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ended_at' => 'immutable_datetime'];
    }
}
