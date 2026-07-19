<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRestrictionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id',
    'user_id',
    'type',
    'reason_code',
    'public_notice_key',
    'private_note',
    'applied_by_id',
    'revoked_by_id',
    'starts_at',
    'expires_at',
    'revoked_at',
    'revocation_reason_code',
])]
final class AccountRestriction extends Model
{
    /** @param Builder<AccountRestriction> $query */
    public function scopeActive(Builder $query): void
    {
        $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    protected static function booted(): void
    {
        self::creating(static function (self $restriction): void {
            $restriction->public_id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountRestrictionType::class,
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
