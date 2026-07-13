<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::Partial, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Ожидает запуска',
            self::Running => 'Выполняется',
            self::Completed => 'Завершён',
            self::Partial => 'Завершён частично',
            self::Failed => 'Ошибка',
            self::Cancelled => 'Отменён',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'muted',
            self::Queued => 'sky',
            self::Running => 'warning',
        };
    }
}
