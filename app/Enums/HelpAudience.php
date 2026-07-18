<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpAudience: string
{
    case Everyone = 'everyone';
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';
    case Premium = 'premium';
    case Staff = 'staff';

    public function label(): string
    {
        return __('help.audiences.'.$this->value);
    }

    public function publiclyIndexable(): bool
    {
        return $this === self::Everyone;
    }

    public function publiclyDiscoverable(): bool
    {
        return $this !== self::Staff;
    }
}
