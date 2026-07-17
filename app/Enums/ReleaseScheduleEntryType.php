<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseScheduleEntryType: string
{
    case SerialPremiere = 'serial_premiere';
    case SeasonPremiere = 'season_premiere';
    case EpisodeRelease = 'episode_release';
    case TranslationRelease = 'translation_release';
    case SubtitleRelease = 'subtitle_release';
    case PortalPublication = 'portal_publication';
    case QualityUpgrade = 'quality_upgrade';
    case SpecialRelease = 'special_release';

    public function label(): string
    {
        return __('calendar.types.'.$this->value);
    }

    public function notificationPreference(): string
    {
        return match ($this) {
            self::SerialPremiere => 'premiere_notifications',
            self::SeasonPremiere => 'season_notifications',
            self::EpisodeRelease, self::SpecialRelease => 'episode_notifications',
            self::TranslationRelease => 'translation_notifications',
            self::SubtitleRelease => 'subtitle_notifications',
            self::PortalPublication, self::QualityUpgrade => 'portal_publication_notifications',
        };
    }
}
