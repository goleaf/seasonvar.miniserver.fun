<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumPaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Chargeback = 'chargeback';

    public function label(): string
    {
        return __("premium.states.{$this->value}");
    }
}
