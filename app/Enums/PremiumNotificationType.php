<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumNotificationType: string
{
    case Activated = 'premium_activated';
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case SubscriptionRenewed = 'subscription_renewed';
    case CancellationScheduled = 'cancellation_scheduled';
    case RefundCompleted = 'refund_completed';
    case PaymentDisputed = 'payment_disputed';
    case ManualGranted = 'manual_access_granted';
    case ManualRevoked = 'manual_access_revoked';
    case PromotionRedeemed = 'promotion_redeemed';

    public function label(): string
    {
        return __("premium.notifications.{$this->value}");
    }
}
