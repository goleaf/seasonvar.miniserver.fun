<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportEventPersistence: string
{
    case Always = 'always';
    case Aggregate = 'aggregate';
    case Sampled = 'sampled';
    case Transient = 'transient';
}
