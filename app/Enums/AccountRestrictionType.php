<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountRestrictionType: string
{
    case LoginSuspended = 'login_suspended';
    case AccountDisabled = 'account_disabled';
    case UnderReview = 'under_review';

    public function blocksAuthentication(): bool
    {
        return in_array($this, [self::LoginSuspended, self::AccountDisabled], true);
    }

    public function label(): string
    {
        return __("administration.restrictions.types.{$this->value}");
    }

    public function noticeKey(): string
    {
        return "administration.restrictions.notices.{$this->value}";
    }
}
