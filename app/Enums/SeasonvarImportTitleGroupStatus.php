<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportTitleGroupStatus: string
{
    case Discovering = 'discovering';
    case Running = 'running';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed], true);
    }
}
