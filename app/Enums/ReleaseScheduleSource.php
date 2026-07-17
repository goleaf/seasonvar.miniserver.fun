<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseScheduleSource: string
{
    case Editorial = 'editorial';
    case Official = 'official';
    case TrustedProvider = 'trusted_provider';
    case Provider = 'provider';
    case Importer = 'importer';
    case Inferred = 'inferred';
    case UserReport = 'user_report';
    case Portal = 'portal';
    case Unknown = 'unknown';

    public function priority(): int
    {
        return match ($this) {
            self::Editorial => 100,
            self::Portal => 95,
            self::Official => 90,
            self::TrustedProvider => 80,
            self::Provider => 70,
            self::Importer => 60,
            self::UserReport => 40,
            self::Inferred => 30,
            self::Unknown => 0,
        };
    }

    public function label(): string
    {
        return __('calendar.sources.'.$this->value);
    }
}
