<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentRestrictionReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case Harassment = 'harassment';
    case RepeatedSpoilers = 'repeated_spoilers';
    case Other = 'other';

    public function label(): string
    {
        return __("comments.restrictions.reasons.{$this->value}");
    }
}
