<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminMembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id',
    'public_id',
    'admin_role_id',
    'status',
    'assigned_by_id',
    'revoked_by_id',
    'reason_code',
    'assigned_at',
    'expires_at',
    'suspended_at',
    'revoked_at',
])]
final class AdminUserRole extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AdminRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'admin_role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    protected static function booted(): void
    {
        self::creating(static function (self $membership): void {
            $membership->public_id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AdminMembershipStatus::class,
            'assigned_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
