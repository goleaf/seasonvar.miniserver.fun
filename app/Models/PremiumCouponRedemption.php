<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $premium_promotion_id
 * @property int $premium_coupon_id
 * @property string $idempotency_key
 * @property CarbonImmutable $redeemed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read PremiumPromotion $promotion
 */
#[Fillable([
    'public_id', 'user_id', 'premium_promotion_id', 'premium_coupon_id', 'idempotency_key', 'redeemed_at',
])]
class PremiumCouponRedemption extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PremiumPromotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(PremiumPromotion::class, 'premium_promotion_id');
    }

    /** @return BelongsTo<PremiumCoupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(PremiumCoupon::class, 'premium_coupon_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['redeemed_at' => 'immutable_datetime'];
    }
}
