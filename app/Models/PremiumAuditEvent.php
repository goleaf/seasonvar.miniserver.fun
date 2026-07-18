<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumAuditAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $actor_id
 * @property int|null $user_id
 * @property PremiumAuditAction $action
 * @property string $resource_type
 * @property string|null $resource_public_id
 * @property string $idempotency_key
 * @property array<string, bool|int|string|null> $context
 * @property CarbonImmutable $occurred_at
 * @property-read User|null $actor
 * @property-read User|null $user
 */
#[Fillable([
    'public_id', 'actor_id', 'user_id', 'action', 'resource_type', 'resource_public_id',
    'idempotency_key', 'context', 'occurred_at',
])]
class PremiumAuditEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('События premium-аудита нельзя изменять.');
        });
        static::deleting(static function (): never {
            throw new LogicException('События premium-аудита нельзя удалять.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => PremiumAuditAction::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
