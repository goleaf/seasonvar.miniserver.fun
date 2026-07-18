<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpReportReason: string
{
    case InstructionsFail = 'instructions_fail';
    case OutdatedScreenshot = 'outdated_screenshot';
    case BrokenLink = 'broken_link';
    case Unclear = 'unclear';
    case Incorrect = 'incorrect';
    case TranslationIncorrect = 'translation_incorrect';
    case FeatureRemoved = 'feature_removed';
    case Other = 'other';

    public function label(): string
    {
        return __('help.reports.reasons.'.$this->value);
    }
}
