<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseScheduleStatus: string
{
    case Scheduled = 'scheduled';
    case Estimated = 'estimated';
    case Confirmed = 'confirmed';
    case Released = 'released';
    case Delayed = 'delayed';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';
    case AwaitingTranslation = 'awaiting_translation';
    case AwaitingSubtitles = 'awaiting_subtitles';
    case AwaitingPortalPublication = 'awaiting_portal_publication';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('calendar.statuses.'.$this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Released, self::Cancelled], true);
    }

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Unknown => [self::Estimated, self::Scheduled, self::Confirmed, self::Cancelled],
            self::Estimated => [self::Scheduled, self::Confirmed, self::Delayed, self::Postponed, self::Cancelled],
            self::Scheduled, self::Confirmed => [self::Released, self::Delayed, self::Postponed, self::Cancelled, self::AwaitingTranslation, self::AwaitingSubtitles, self::AwaitingPortalPublication],
            self::Delayed, self::Postponed => [self::Estimated, self::Scheduled, self::Confirmed, self::Released, self::Cancelled],
            self::AwaitingTranslation, self::AwaitingSubtitles, self::AwaitingPortalPublication => [self::Released, self::Delayed, self::Postponed, self::Cancelled],
            self::Released, self::Cancelled => [],
        };
    }
}
