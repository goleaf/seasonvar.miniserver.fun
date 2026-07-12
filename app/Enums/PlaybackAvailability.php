<?php

declare(strict_types=1);

namespace App\Enums;

enum PlaybackAvailability: string
{
    case Ready = 'ready';
    case AuthenticationRequired = 'authentication_required';
    case NotYetPublished = 'not_yet_published';
    case Expired = 'expired';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case NotFound = 'not_found';

    public function message(): string
    {
        return match ($this) {
            self::Ready => 'Видео доступно.',
            self::AuthenticationRequired => 'Для просмотра необходимо войти.',
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
            self::NotYetPublished => 425,
            self::Expired => 410,
            self::TemporarilyUnavailable => 503,
            self::NotFound => 404,
        };
    }
}
