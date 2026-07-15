<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewRestrictionType: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';

    public function label(): string
    {
        return __('reviews.restrictions.types.'.$this->value);
    }
}
