<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewReportCategory: string
{
    case Spam = 'spam';
    case Advertising = 'advertising';
    case Harassment = 'harassment';
    case HateOrAbuse = 'hate_or_abuse';
    case UnmarkedSpoiler = 'unmarked_spoiler';
    case PersonalInformation = 'personal_information';
    case Duplicate = 'duplicate';
    case OffTopic = 'off_topic';
    case Plagiarism = 'plagiarism';
    case Other = 'other';

    public function label(): string
    {
        return __('reviews.reports.categories.'.$this->value);
    }
}
