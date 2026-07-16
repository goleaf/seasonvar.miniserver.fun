<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssuePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return __("issues.priorities.{$this->value}");
    }
}
