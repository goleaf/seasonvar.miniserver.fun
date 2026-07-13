<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaHealthErrorCategory: string
{
    case InvalidUrl = 'invalid_url';
    case Timeout = 'timeout';
    case Connection = 'connection';
    case UnsafeRedirect = 'unsafe_redirect';
    case Authentication = 'authentication';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case ProviderTemporary = 'provider_temporary';
    case ResponseTooLarge = 'response_too_large';
    case RangeUnsupported = 'range_unsupported';
    case InvalidManifest = 'invalid_manifest';
    case Unknown = 'unknown';
}
