<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentModerationReason: string
{
    case Approved = 'approved';
    case Spam = 'spam';
    case Abuse = 'abuse';
    case Harassment = 'harassment';
    case Spoiler = 'spoiler';
    case PersonalInformation = 'personal_information';
    case IllegalContent = 'illegal_content';
    case OffTopic = 'off_topic';
    case Other = 'other';

    public function label(): string
    {
        return __("comments.moderation.reasons.{$this->value}");
    }
}
