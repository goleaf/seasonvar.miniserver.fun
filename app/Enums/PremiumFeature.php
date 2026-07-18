<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiumFeature: string
{
    case PremiumAccess = 'premium_access';

    public function label(): string
    {
        return __("premium.features.{$this->value}.name");
    }

    public function description(): string
    {
        return __("premium.features.{$this->value}.description");
    }
}
