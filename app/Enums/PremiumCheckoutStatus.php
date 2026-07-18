<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumCheckoutStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
