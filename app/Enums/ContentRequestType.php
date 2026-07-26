<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentRequestType: string
{
    case Serial = 'serial';
    case Season = 'season';
    case Episode = 'episode';
    case Translation = 'translation';
    case Subtitles = 'subtitles';
    case QualityUpgrade = 'quality_upgrade';
    case MetadataCorrection = 'metadata_correction';
    case EpisodeListCorrection = 'episode_list_correction';
    case BrokenContentRestoration = 'broken_content_restoration';
    case Other = 'other_content_request';

    public function label(): string
    {
        return __('requests.types.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('requests.types.'.$this->value.'.description');
    }

    public function requiresCatalogTitle(): bool
    {
        return $this !== self::Serial && $this !== self::Other;
    }

    public function isAdministrativeOnly(): bool
    {
        return in_array($this, [
            self::MetadataCorrection,
            self::EpisodeListCorrection,
        ], true);
    }

    /** @return list<self> */
    public static function publicCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => ! $type->isAdministrativeOnly(),
        ));
    }

    /** @return list<string> */
    public static function administrativeOnlyValues(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $type): bool => $type->isAdministrativeOnly(),
            )),
        );
    }
}
