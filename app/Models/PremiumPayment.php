<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumPaymentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int|null $premium_plan_id
 * @property int|null $premium_checkout_session_id
 * @property int|null $premium_subscription_id
 * @property string $provider_code
 * @property string $provider_payment_id
 * @property PremiumPaymentStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property int $refunded_amount_minor
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read PremiumPlan|null $plan
 * @property-read PremiumSubscription|null $subscription
 * @property-read Collection<int, PremiumRefund> $refunds
 */
#[Fillable([
    'public_id', 'user_id', 'premium_plan_id', 'premium_checkout_session_id',
    'premium_subscription_id', 'provider_code', 'provider_payment_id', 'status',
    'amount_minor', 'currency', 'refunded_amount_minor', 'confirmed_at', 'failed_at',
    'provider_created_at', 'provider_updated_at',
])]
class PremiumPayment extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PremiumPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumPlan::class, 'premium_plan_id');
    }

    /** @return BelongsTo<PremiumSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PremiumSubscription::class, 'premium_subscription_id');
    }

    /** @return HasMany<PremiumRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PremiumRefund::class);
    }

    /** @return HasMany<PremiumEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PremiumEntitlement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PremiumPaymentStatus::class,
            'amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
        ];
    }
}
