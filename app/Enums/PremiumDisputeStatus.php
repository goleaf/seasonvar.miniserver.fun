<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumDisputeStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
    case Closed = 'closed';
}
