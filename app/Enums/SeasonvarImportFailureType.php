<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportFailureType: string
{
    case Transient = 'transient';
    case Permanent = 'permanent';
}
