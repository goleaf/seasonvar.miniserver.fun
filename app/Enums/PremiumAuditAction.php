<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumAuditAction: string
{
    case CheckoutCreated = 'checkout.created';
    case PaymentConfirmed = 'payment.confirmed';
    case PaymentFailed = 'payment.failed';
    case EntitlementGranted = 'entitlement.granted';
    case EntitlementRevoked = 'entitlement.revoked';
    case SubscriptionUpdated = 'subscription.updated';
    case RefundUpdated = 'refund.updated';
    case DisputeUpdated = 'dispute.updated';
    case PromotionCreated = 'promotion.created';
    case CouponCreated = 'coupon.created';
    case CouponRedeemed = 'coupon.redeemed';
    case ProviderEventProcessed = 'provider_event.processed';

    public function label(): string
    {
        return __('premium.audit_actions.'.str_replace('.', '_', $this->value));
    }
}
