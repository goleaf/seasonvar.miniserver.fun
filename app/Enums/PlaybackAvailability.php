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
        return match ($this) {
            self::Ready => 'Видео доступно.',
            self::AuthenticationRequired => 'Для просмотра необходимо войти.',
            self::PlanRequired => 'Для просмотра нужен подходящий тариф.',
            self::RegionBlocked => 'Видео недоступно в вашем регионе.',
            self::ProfileRestricted => 'Контент ограничен настройками профиля.',
            self::NotYetPublished => 'Видео ещё не опубликовано.',
            self::Expired => 'Срок доступности видео истёк.',
            self::TemporarilyUnavailable => 'Видео временно недоступно.',
            self::NotFound => 'Видео не найдено.',
        };
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
