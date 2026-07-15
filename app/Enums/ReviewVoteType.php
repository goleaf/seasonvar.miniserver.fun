<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewVoteType: string
{
    case Helpful = 'helpful';
    case NotHelpful = 'not_helpful';

    public function label(): string
    {
        return __('reviews.votes.'.$this->value);
    }
}
