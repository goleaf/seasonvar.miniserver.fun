<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogTitleRelationType: string
{
    case Sequel = 'sequel';
    case Prequel = 'prequel';
    case SpinOff = 'spin_off';
    case SpinOffFrom = 'spin_off_from';
    case Remake = 'remake';
    case SameUniverse = 'same_universe';
    case Companion = 'companion';
    case ManualSimilar = 'manual_similar';
    case ProviderRelated = 'provider_related';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel => self::Prequel,
            self::Prequel => self::Sequel,
            self::SpinOff => self::SpinOffFrom,
            self::SpinOffFrom => self::SpinOff,
            default => $this,
        };
    }

    public function isDirectional(): bool
    {
        return in_array($this, [self::Sequel, self::Prequel, self::SpinOff, self::SpinOffFrom], true);
    }
}
