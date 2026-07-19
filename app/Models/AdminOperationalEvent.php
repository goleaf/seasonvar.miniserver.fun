<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['public_id', 'actor_id', 'action_code', 'target_code', 'status', 'result_summary', 'idempotency_key', 'occurred_at'])]
final class AdminOperationalEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            $event->public_id ??= (string) Str::uuid();
        });
        self::updating(static function (): never {
            throw new LogicException('Системные операционные события нельзя изменять.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Системные операционные события нельзя удалять.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result_summary' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
