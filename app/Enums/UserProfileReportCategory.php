<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProfileReportCategory: string
{
    case Impersonation = 'impersonation';
    case AbusiveBiography = 'abusive_biography';
    case ProhibitedImage = 'prohibited_image';
    case Spam = 'spam';
    case OffensiveUsername = 'offensive_username';
    case Harassment = 'harassment';
    case PersonalInformation = 'personal_information';
    case Other = 'other';

    public function label(): string
    {
        return __('profiles.reports.categories.'.$this->value);
    }
}
