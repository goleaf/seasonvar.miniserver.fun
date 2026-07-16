<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProfileModerationStatus: string
{
    case Active = 'active';
    case Hidden = 'hidden';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __('profiles.moderation.'.$this->value);
    }
}
