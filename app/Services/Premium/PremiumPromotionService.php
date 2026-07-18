<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumAuditAction;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Models\PremiumCoupon;
use App\Models\PremiumCouponRedemption;
use App\Models\PremiumPromotion;
use App\Models\User;
use App\ValueObjects\PremiumCouponCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PremiumPromotionService
{
    public function __construct(
        private readonly PremiumEntitlementService $entitlements,
        private readonly PremiumAuditService $audit,
    ) {}

    public function createPromotion(
        User $actor,
        string $code,
        int $durationDays,
        ?CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        ?int $totalLimit,
        int $perUserLimit,
    ): PremiumPromotion {
        $normalizedCode = Str::of($code)->trim()->lower()->value();

        if (preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/', $normalizedCode) !== 1
            || $durationDays < 1 || $durationDays > 3650
            || $perUserLimit < 1 || $perUserLimit > 20
            || ($totalLimit !== null && $totalLimit < 1)
            || ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt))) {
            throw new InvalidArgumentException('Некорректные параметры premium-кампании.');
        }

        return DB::transaction(function () use ($actor, $normalizedCode, $durationDays, $startsAt, $endsAt, $totalLimit, $perUserLimit): PremiumPromotion {
            if (PremiumPromotion::query()->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['promotionCode' => [__('premium.errors.promotion_code_taken')]]);
            }

            $promotion = PremiumPromotion::query()->create([
                'public_id' => (string) Str::uuid(),
                'code' => $normalizedCode,
                'duration_days' => $durationDays,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'total_limit' => $totalLimit,
                'per_user_limit' => $perUserLimit,
                'is_active' => true,
            ]);
            $this->audit->record(
                PremiumAuditAction::PromotionCreated,
                'promotion',
                $promotion->public_id,
                'promotion-created:'.$promotion->public_id,
                actor: $actor,
                context: [
                    'code' => $promotion->code,
                    'duration_days' => $durationDays,
                    'total_limit' => $totalLimit,
                    'per_user_limit' => $perUserLimit,
                ],
            );

            return $promotion;
        }, attempts: 3);
    }

    /** @return array{coupon: PremiumCoupon, code: string} */
    public function createCoupon(User $actor, PremiumPromotion $promotion, ?int $redemptionLimit): array
    {
        if ($redemptionLimit !== null && $redemptionLimit < 1) {
            throw new InvalidArgumentException('Лимит активаций промокода должен быть положительным.');
        }

        $code = PremiumCouponCode::generate();
        $coupon = DB::transaction(function () use ($actor, $promotion, $redemptionLimit, $code): PremiumCoupon {
            $lockedPromotion = PremiumPromotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $coupon = PremiumCoupon::query()->create([
                'premium_promotion_id' => $lockedPromotion->id,
                'code_hash' => $code->hash(),
                'code_hint' => $code->hint(),
                'redemption_limit' => $redemptionLimit,
                'is_active' => true,
            ]);
            $this->audit->record(
                PremiumAuditAction::CouponCreated,
                'coupon',
                (string) $coupon->id,
                'coupon-created:'.$coupon->id,
                actor: $actor,
                context: [
                    'promotion' => $lockedPromotion->public_id,
                    'code_hint' => $coupon->code_hint,
                    'redemption_limit' => $redemptionLimit,
                ],
            );

            return $coupon;
        }, attempts: 3);

        return ['coupon' => $coupon, 'code' => $code->value];
    }

    public function redeem(User $user, string $rawCode): PremiumCouponRedemption
    {
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['couponCode' => [__('premium.errors.verified_account_required')]]);
        }

        try {
            $code = PremiumCouponCode::from($rawCode);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['couponCode' => [__('premium.errors.invalid_coupon')]]);
        }

        return DB::transaction(function () use ($user, $code): PremiumCouponRedemption {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $coupon = PremiumCoupon::query()->where('code_hash', $code->hash())->lockForUpdate()->first();

            if (! $coupon instanceof PremiumCoupon || ! $coupon->is_active) {
                throw ValidationException::withMessages(['couponCode' => [__('premium.errors.invalid_coupon')]]);
            }

            $existing = PremiumCouponRedemption::query()
                ->where('user_id', $user->id)
                ->where('premium_coupon_id', $coupon->id)
                ->first();

            if ($existing instanceof PremiumCouponRedemption) {
                return $existing;
            }

            $promotion = PremiumPromotion::query()->lockForUpdate()->findOrFail($coupon->premium_promotion_id);
            $now = CarbonImmutable::now();

            if (! $promotion->is_active
                || $promotion->starts_at?->isFuture() === true
                || $promotion->ends_at?->lessThanOrEqualTo($now) === true) {
                throw ValidationException::withMessages(['couponCode' => [__('premium.errors.expired_coupon')]]);
            }

            $promotionRedemptions = PremiumCouponRedemption::query()->where('premium_promotion_id', $promotion->id);

            if ($promotion->total_limit !== null && (clone $promotionRedemptions)->count() >= $promotion->total_limit) {
                throw ValidationException::withMessages(['couponCode' => [__('premium.errors.coupon_limit')]]);
            }

            if ((clone $promotionRedemptions)->where('user_id', $user->id)->count() >= $promotion->per_user_limit) {
                throw ValidationException::withMessages(['couponCode' => [__('premium.errors.coupon_user_limit')]]);
            }

            if ($coupon->redemption_limit !== null
                && PremiumCouponRedemption::query()->where('premium_coupon_id', $coupon->id)->count() >= $coupon->redemption_limit) {
                throw ValidationException::withMessages(['couponCode' => [__('premium.errors.coupon_limit')]]);
            }

            $redemption = PremiumCouponRedemption::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'premium_promotion_id' => $promotion->id,
                'premium_coupon_id' => $coupon->id,
                'idempotency_key' => hash('sha256', 'coupon-redemption:'.$user->id.':'.$coupon->id),
                'redeemed_at' => $now,
            ]);
            $this->entitlements->grantDuration(
                $user,
                PremiumFeature::PremiumAccess,
                PremiumEntitlementSource::Promotion,
                $promotion->duration_days,
                'promotion-redemption:'.$redemption->public_id,
                reasonCode: 'promotion',
                redemption: $redemption,
            );
            $this->audit->record(
                PremiumAuditAction::CouponRedeemed,
                'redemption',
                $redemption->public_id,
                'coupon-redeemed:'.$redemption->public_id,
                $user,
                context: [
                    'promotion' => $promotion->public_id,
                    'code_hint' => $coupon->code_hint,
                    'duration_days' => $promotion->duration_days,
                ],
            );

            return $redemption;
        }, attempts: 3);
    }
}
