<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewModerationReason: string
{
    case Approved = 'approved';
    case Spam = 'spam';
    case Abuse = 'abuse';
    case UnsafeLink = 'unsafe_link';
    case UnmarkedSpoiler = 'unmarked_spoiler';
    case OffTopic = 'off_topic';
    case Duplicate = 'duplicate';
    case Other = 'other';

    public function label(): string
    {
        return __('reviews.moderation.reasons.'.$this->value);
    }
}
