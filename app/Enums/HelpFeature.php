<?php

declare(strict_types=1);

namespace App\Enums;

enum HelpFeature: string
{
    case General = 'general';
    case Player = 'player';
    case Audio = 'audio';
    case Subtitles = 'subtitles';
    case Quality = 'quality';
    case Progress = 'progress';
    case Authentication = 'authentication';
    case Sessions = 'sessions';
    case Settings = 'settings';
    case Privacy = 'privacy';
    case Library = 'library';
    case Collections = 'collections';
    case Community = 'community';
    case Calendar = 'calendar';
    case Recommendations = 'recommendations';
    case Requests = 'requests';
    case Tickets = 'tickets';
    case Premium = 'premium';
    case Region = 'region';
    case Devices = 'devices';
    case Accessibility = 'accessibility';
    case Notifications = 'notifications';
    case Security = 'security';

    public function label(): string
    {
        return __('help.features.'.$this->value);
    }
}
