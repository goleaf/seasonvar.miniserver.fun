<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewRestrictionReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case RepeatedViolations = 'repeated_violations';
    case BanEvasion = 'ban_evasion';
    case Other = 'other';

    public function label(): string
    {
        return __('reviews.restrictions.reasons.'.$this->value);
    }
}
