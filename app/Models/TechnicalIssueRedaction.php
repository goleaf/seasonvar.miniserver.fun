<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** @property CarbonImmutable $created_at */
#[Fillable(['technical_issue_id', 'technical_issue_message_id', 'actor_id', 'field', 'reason_code', 'before_hash', 'after_hash', 'created_at'])]
final class TechnicalIssueRedaction extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Redaction audit is immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Redaction audit is immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
