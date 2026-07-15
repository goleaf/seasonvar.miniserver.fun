<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewTargetType: string
{
    case Title = 'title';

    public function label(): string
    {
        return __('reviews.scope.title');
    }
}
