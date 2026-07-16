<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestRejectionReason: string
{
    case AlreadyAvailable = 'already_available';
    case DuplicateRequest = 'duplicate_request';
    case InsufficientInformation = 'insufficient_information';
    case InvalidRequest = 'invalid_request';
    case UnsupportedContentType = 'unsupported_content_type';
    case UnverifiableContent = 'unverifiable_content';
    case PolicyLimit = 'licensing_or_policy_limit';
    case TechnicallyUnavailable = 'technically_unavailable';
    case ProhibitedSource = 'prohibited_source';
    case Other = 'other';

    public function label(): string
    {
        return __('requests.rejection_reasons.'.$this->value);
    }
}
