<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProfileVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return __('profiles.visibility.'.$this->value);
    }
}
