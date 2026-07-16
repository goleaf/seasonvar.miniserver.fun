<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaFileSizeCheckStatus: string
{
    case Pending = 'pending';
    case Known = 'known';
    case Unknown = 'unknown';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
}
