<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TechnicalIssueMessageVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TechnicalIssueMessageVisibility $visibility
 * @property CarbonImmutable|null $redacted_at
 * @property-read User|null $author
 */
#[Fillable(['public_id', 'technical_issue_id', 'author_id', 'visibility', 'kind', 'body', 'body_hash', 'submission_key', 'redacted_at'])]
final class TechnicalIssueMessage extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<TechnicalIssueAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TechnicalIssueAttachment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => TechnicalIssueMessageVisibility::class,
            'redacted_at' => 'immutable_datetime',
        ];
    }
}
