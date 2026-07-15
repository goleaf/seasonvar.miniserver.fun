<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentReactionType: string
{
    case Up = 'up';
    case Down = 'down';

    public function label(): string
    {
        return __("comments.reactions.{$this->value}");
    }
}
