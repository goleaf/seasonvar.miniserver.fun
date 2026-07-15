<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentReportCategory: string
{
    case Spam = 'spam';
    case Advertising = 'advertising';
    case Harassment = 'harassment';
    case HateAbuse = 'hate_abuse';
    case Spoiler = 'spoiler';
    case PersonalInformation = 'personal_information';
    case IllegalContent = 'illegal_content';
    case OffTopic = 'off_topic';
    case Other = 'other';

    public function label(): string
    {
        return __("comments.reports.categories.{$this->value}");
    }
}
