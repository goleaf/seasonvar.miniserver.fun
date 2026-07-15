<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentRestrictionType: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';

    public function label(): string
    {
        return __("comments.restrictions.types.{$this->value}");
    }
}
