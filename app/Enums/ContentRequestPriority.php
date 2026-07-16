<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return __('requests.priorities.'.$this->value);
    }
}
