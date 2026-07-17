<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseScheduleEntryType;
use App\Support\PlainText;

final class ReleaseScheduleIdentity
{
    public function key(
        ReleaseScheduleEntryType $type,
        int $catalogTitleId,
        ?int $seasonId = null,
        ?int $episodeId = null,
        ?int $licensedMediaId = null,
        ?string $languageCode = null,
        ?string $translationName = null,
    ): string {
        $target = match ($type) {
            ReleaseScheduleEntryType::SerialPremiere => ['title-'.$catalogTitleId],
            ReleaseScheduleEntryType::SeasonPremiere => ['title-'.$catalogTitleId, 'season-'.$seasonId],
            ReleaseScheduleEntryType::EpisodeRelease, ReleaseScheduleEntryType::SpecialRelease => [
                'title-'.$catalogTitleId, 'season-'.$seasonId, 'episode-'.$episodeId,
            ],
            ReleaseScheduleEntryType::TranslationRelease, ReleaseScheduleEntryType::SubtitleRelease,
            ReleaseScheduleEntryType::PortalPublication => ['title-'.$catalogTitleId, 'episode-'.$episodeId],
            ReleaseScheduleEntryType::QualityUpgrade => ['title-'.$catalogTitleId, 'media-'.$licensedMediaId],
        };
        $dimensions = match ($type) {
            ReleaseScheduleEntryType::TranslationRelease => [
                $this->language($languageCode),
                $this->translation($translationName),
            ],
            ReleaseScheduleEntryType::SubtitleRelease => [$this->language($languageCode)],
            default => [],
        };

        return implode(':', array_filter([$type->value, ...$target, ...$dimensions], is_string(...)));
    }

    private function language(?string $languageCode): ?string
    {
        return filled($languageCode)
            ? 'language-'.mb_strtolower(PlainText::clean((string) $languageCode, 16))
            : null;
    }

    private function translation(?string $translationName): ?string
    {
        return filled($translationName)
            ? 'translation-'.hash('sha256', mb_strtolower(PlainText::clean((string) $translationName, 120)))
            : null;
    }
}
