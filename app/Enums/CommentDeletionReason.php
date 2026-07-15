<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentDeletionReason: string
{
    case Author = 'author';
    case Moderator = 'moderator';
    case Privacy = 'privacy';
}
