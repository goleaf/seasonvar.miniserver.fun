<?php

declare(strict_types=1);

namespace App\Enums;

enum ReviewDeletionReason: string
{
    case Author = 'author';
    case Moderator = 'moderator';
    case Merged = 'merged';
}
