<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumSubscriptionStatus: string
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case CancellationScheduled = 'cancellation_scheduled';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Unpaid = 'unpaid';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __("premium.states.{$this->value}");
    }

    public function mayHavePortalAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::GracePeriod, self::CancellationScheduled], true);
    }
}
