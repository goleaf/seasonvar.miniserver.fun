<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
