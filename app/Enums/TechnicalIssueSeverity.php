<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return __("issues.severities.{$this->value}");
    }

    public function sortRank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
