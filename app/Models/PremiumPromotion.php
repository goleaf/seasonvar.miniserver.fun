<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property int $duration_days
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $total_limit
 * @property int $per_user_limit
 * @property bool $is_active
 * @property int $redemptions_count
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'public_id', 'code', 'duration_days', 'starts_at', 'ends_at', 'total_limit',
    'per_user_limit', 'is_active',
])]
class PremiumPromotion extends Model
{
    /** @param Builder<PremiumPromotion> $query */
    public function scopeActiveAt(Builder $query, mixed $at): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /** @return HasMany<PremiumCoupon, $this> */
    public function coupons(): HasMany
    {
        return $this->hasMany(PremiumCoupon::class);
    }

    /** @return HasMany<PremiumCouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PremiumCouponRedemption::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'total_limit' => 'integer',
            'per_user_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
