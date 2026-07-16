<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueTargetType: string
{
    case Title = 'title';
    case Season = 'season';
    case Episode = 'episode';
    case Media = 'media';
    case Translation = 'translation';
    case Page = 'page';
    case Account = 'account';
    case Notification = 'notification';
    case Calendar = 'calendar';
    case Search = 'search';
    case General = 'general';

    public function label(): string
    {
        return __("issues.targets.{$this->value}");
    }
}
