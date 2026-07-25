<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogCollectionCategorySuggestionConfidence: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 85 => self::High,
            $score >= 70 => self::Medium,
            $score >= 60 => self::Low,
            default => self::None,
        };
    }

    public function label(): string
    {
        return __("collections.classification.confidence.{$this->value}");
    }

    public function variant(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'neutral',
            self::Low => 'warning',
            self::None => 'muted',
        };
    }
}
