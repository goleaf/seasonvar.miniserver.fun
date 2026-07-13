<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'actor_id',
    'action',
    'resource_type',
    'resource_id',
    'before_version',
    'after_version',
    'changed_fields',
    'occurred_at',
])]
class AdminAuditEvent extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('События административного аудита нельзя изменять.');
        });
        static::deleting(static function (): never {
            throw new LogicException('События административного аудита нельзя удалять.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AdminAuditAction::class,
            'resource_id' => 'integer',
            'changed_fields' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
