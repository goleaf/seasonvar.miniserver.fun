<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaHealthStatus: string
{
    case Active = 'active';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Disabled = 'disabled';

    public function isPlayable(): bool
    {
        return $this === self::Active || $this === self::Degraded;
    }
}
