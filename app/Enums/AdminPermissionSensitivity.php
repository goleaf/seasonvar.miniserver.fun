<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminPermissionSensitivity: string
{
    case Standard = 'standard';
    case Sensitive = 'sensitive';
    case HighlySensitive = 'highly_sensitive';
}
