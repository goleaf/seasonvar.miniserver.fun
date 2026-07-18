<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountSettingsSection: string
{
    case Profile = 'profile';
    case Appearance = 'appearance';
    case Playback = 'playback';
    case Privacy = 'privacy';
    case Notifications = 'notifications';
    case Collections = 'collections';
    case Premium = 'premium';
    case Security = 'security';
    case Data = 'data';

    public function label(): string
    {
        return __("settings.navigation.{$this->value}");
    }

    public function icon(): string
    {
        return match ($this) {
            self::Profile => 'fa-solid fa-user',
            self::Appearance => 'fa-solid fa-language',
            self::Playback => 'fa-solid fa-circle-play',
            self::Privacy => 'fa-solid fa-user-shield',
            self::Notifications => 'fa-solid fa-bell',
            self::Collections => 'fa-solid fa-layer-group',
            self::Premium => 'fa-solid fa-crown',
            self::Security => 'fa-solid fa-shield-halved',
            self::Data => 'fa-solid fa-file-shield',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $section): string => $section->value, self::cases());
    }
}
