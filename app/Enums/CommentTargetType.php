<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentTargetType: string
{
    case Title = 'title';
    case Season = 'season';
    case Episode = 'episode';
    case Collection = 'collection';

    public function label(): string
    {
        return __("comments.targets.{$this->value}");
    }
}
