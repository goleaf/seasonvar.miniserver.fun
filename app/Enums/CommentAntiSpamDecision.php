<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentAntiSpamDecision: string
{
    case Allow = 'allow';
    case Review = 'review';
}
