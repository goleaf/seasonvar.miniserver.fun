<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewStatus: string
{
    case Published = 'published';
    case Pending = 'pending';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Spam = 'spam';
    case Removed = 'removed';

    public function label(): string
    {
        return __('reviews.statuses.'.$this->value);
    }

    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
