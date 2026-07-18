<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpFeedbackValue: string
{
    case Helpful = 'helpful';
    case NotHelpful = 'not_helpful';

    public function label(): string
    {
        return __('help.feedback.values.'.$this->value);
    }
}
