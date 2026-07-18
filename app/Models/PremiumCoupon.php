<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $premium_promotion_id
 * @property string $code_hash
 * @property string $code_hint
 * @property int|null $redemption_limit
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['premium_promotion_id', 'code_hash', 'code_hint', 'redemption_limit', 'is_active'])]
class PremiumCoupon extends Model
{
    /** @return BelongsTo<PremiumPromotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(PremiumPromotion::class, 'premium_promotion_id');
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
            'redemption_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
