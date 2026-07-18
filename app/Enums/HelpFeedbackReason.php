<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpFeedbackReason: string
{
    case DidNotSolve = 'did_not_solve';
    case Unclear = 'unclear';
    case Outdated = 'outdated';
    case MissingSteps = 'missing_steps';
    case TranslationIssue = 'translation_issue';
    case Other = 'other';

    public function label(): string
    {
        return __('help.feedback.reasons.'.$this->value);
    }
}
