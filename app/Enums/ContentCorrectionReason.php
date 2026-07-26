<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentCorrectionReason: string
{
    case NotRelated = 'not_related';
    case Duplicate = 'duplicate';
    case TranslationError = 'translation_error';
    case TooBroad = 'too_broad';
    case ImportError = 'import_error';

    public function label(): string
    {
        return __('requests.correction_reasons.'.$this->value);
    }
}
