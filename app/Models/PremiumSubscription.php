<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumSubscriptionStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property int $premium_plan_id
 * @property string $provider_code
 * @property string|null $provider_customer_id
 * @property string $provider_subscription_id
 * @property PremiumSubscriptionStatus $status
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read PremiumPlan $plan
 */
#[Fillable([
    'public_id', 'user_id', 'premium_plan_id', 'provider_code', 'provider_customer_id',
    'provider_subscription_id', 'status', 'current_period_start', 'current_period_end',
    'trial_ends_at', 'grace_ends_at', 'cancel_at_period_end', 'cancelled_at', 'ended_at',
    'provider_updated_at',
])]
class PremiumSubscription extends Model
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

    /** @return HasMany<PremiumPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PremiumPayment::class);
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
            'status' => PremiumSubscriptionStatus::class,
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
        ];
    }
}
