<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpOwnerTeam: string
{
    case Support = 'support';
    case Player = 'player';
    case AccountSecurity = 'account_security';
    case ContentOperations = 'content_operations';
    case Premium = 'premium';
    case Accessibility = 'accessibility';

    public function label(): string
    {
        return __('help.owner_teams.'.$this->value);
    }
}
