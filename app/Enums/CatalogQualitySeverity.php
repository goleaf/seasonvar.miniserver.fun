<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogQualitySeverity: string
{
    case Healthy = 'healthy';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Notice => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }
}
