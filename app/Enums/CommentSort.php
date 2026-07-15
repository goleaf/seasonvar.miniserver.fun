<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Popular = 'popular';

    public function label(): string
    {
        return __("comments.sort.{$this->value}");
    }
}
