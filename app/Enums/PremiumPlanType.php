<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumPlanType: string
{
    case OneTimeDuration = 'one_time_duration';
    case RecurringSubscription = 'recurring_subscription';
    case Lifetime = 'lifetime';

    public function label(): string
    {
        return __("premium.plans.types.{$this->value}");
    }
}
