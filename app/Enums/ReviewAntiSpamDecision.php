<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewAntiSpamDecision: string
{
    case Allow = 'allow';
    case Review = 'review';
}
