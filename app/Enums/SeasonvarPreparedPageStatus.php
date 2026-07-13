<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarPreparedPageStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Failed = 'failed';
    case Applied = 'applied';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Prepared, self::Failed, self::Applied], true);
    }
}
