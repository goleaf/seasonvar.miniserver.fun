<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybackAvailability: string
{
    case Ready = 'ready';
    case AuthenticationRequired = 'authentication_required';
    case PlanRequired = 'plan_required';
    case RegionBlocked = 'region_blocked';
    case ProfileRestricted = 'profile_restricted';
    case NotYetPublished = 'not_yet_published';
    case Expired = 'expired';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case NotFound = 'not_found';

    public function message(): string
    {
        return __('catalog.player.availability.'.$this->value);
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Ready => 200,
            self::AuthenticationRequired => 401,
            self::PlanRequired => 402,
            self::RegionBlocked => 451,
            self::ProfileRestricted => 403,
            self::NotYetPublished => 425,
            self::Expired => 410,
            self::TemporarilyUnavailable => 503,
            self::NotFound => 404,
        };
    }
}
