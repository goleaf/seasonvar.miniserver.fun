<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TechnicalIssueStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property TechnicalIssueStatus|null $from_status
 * @property TechnicalIssueStatus $to_status
 * @property CarbonImmutable $created_at
 */
#[Fillable(['technical_issue_id', 'actor_id', 'from_status', 'to_status', 'public_reason_code', 'public_message', 'private_note', 'idempotency_key', 'created_at'])]
final class TechnicalIssueStatusHistory extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Technical issue history is immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Technical issue history is immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => TechnicalIssueStatus::class,
            'to_status' => TechnicalIssueStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
