<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumSubscriptionStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $premium_plan_id
 * @property int|null $premium_subscription_id
 * @property int|null $premium_payment_id
 * @property int|null $premium_coupon_redemption_id
 * @property PremiumFeature $feature_code
 * @property PremiumEntitlementSource $source
 * @property string|null $source_reference
 * @property string $application_key
 * @property string|null $reason_code
 * @property string|null $private_note
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_lifetime
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revocation_reason_code
 * @property string|null $revocation_private_note
 * @property int|null $granted_by_id
 * @property int|null $revoked_by_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read PremiumPlan|null $plan
 * @property-read PremiumSubscription|null $subscription
 * @property-read PremiumPayment|null $payment
 */
#[Fillable([
    'public_id', 'user_id', 'premium_plan_id', 'premium_subscription_id', 'premium_payment_id',
    'premium_coupon_redemption_id', 'feature_code', 'source', 'source_reference', 'application_key',
    'reason_code', 'private_note', 'starts_at', 'ends_at', 'is_lifetime', 'revoked_at',
    'revocation_reason_code', 'revocation_private_note', 'granted_by_id', 'revoked_by_id',
])]
class PremiumEntitlement extends Model
{
    /** @param Builder<PremiumEntitlement> $query */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): void
    {
        $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query->where('is_lifetime', true)
                    ->orWhere('ends_at', '>', $at)
                    ->orWhere(function (Builder $query) use ($at): void {
                        $query->where('source', PremiumEntitlementSource::Subscription->value)
                            ->whereHas('subscription', fn (Builder $subscription): Builder => $subscription
                                ->where('status', PremiumSubscriptionStatus::GracePeriod->value)
                                ->where('grace_ends_at', '>', $at));
                    });
            });
    }

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($at)
            && ($this->is_lifetime || $this->ends_at?->greaterThan($at) === true || $this->graceActiveAt($at));
    }

    public function graceActiveAt(CarbonInterface $at): bool
    {
        return $this->source === PremiumEntitlementSource::Subscription
            && $this->premium_subscription_id !== null
            && $this->subscription->status === PremiumSubscriptionStatus::GracePeriod
            && $this->subscription->grace_ends_at?->greaterThan($at) === true;
    }

    public function effectiveEndsAt(CarbonInterface $at): ?CarbonImmutable
    {
        if (! $this->graceActiveAt($at)) {
            return $this->ends_at;
        }

        return $this->ends_at?->greaterThan($this->subscription->grace_ends_at) === true
            ? $this->ends_at
            : $this->subscription->grace_ends_at;
    }

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

    /** @return BelongsTo<PremiumPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PremiumPayment::class, 'premium_payment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'feature_code' => PremiumFeature::class,
            'source' => PremiumEntitlementSource::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_lifetime' => 'boolean',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
