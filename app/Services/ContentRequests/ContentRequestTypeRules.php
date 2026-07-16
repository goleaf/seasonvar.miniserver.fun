<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentRequestType;
use App\Enums\ReleaseKind;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Support\Str;

final class ContentRequestTypeRules
{
    public function assert(ContentRequestInput $input): void
    {
        if (mb_strlen($input->title) < 2 || mb_strlen($input->title) > 240) {
            throw new ContentRequestActionException('requests.errors.invalid_title');
        }

        if ($input->releaseYear !== null && ($input->releaseYear < 1900 || $input->releaseYear > (int) date('Y') + 3)) {
            throw new ContentRequestActionException('requests.errors.invalid_year');
        }

        $this->assertLanguages($input);
        $this->assertTarget($input);

        if ($input->seasonNumber !== null && $input->seasonNumber > 999) {
            throw new ContentRequestActionException('requests.errors.season_required');
        }

        if ($input->episodeNumber !== null && $input->episodeNumber > 99_999) {
            throw new ContentRequestActionException('requests.errors.episode_required');
        }

        if ($input->seasonKind !== null && ReleaseKind::tryFrom($input->seasonKind) === null) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        if ($input->episodeReleaseDate !== null && ! $this->validDate($input->episodeReleaseDate)) {
            throw new ContentRequestActionException('requests.errors.invalid_release_date');
        }

        if ($input->type === ContentRequestType::Season && $input->seasonNumber === null) {
            throw new ContentRequestActionException('requests.errors.season_required');
        }

        if ($input->type === ContentRequestType::Episode && ($input->seasonId === null || $input->episodeNumber === null)) {
            throw new ContentRequestActionException('requests.errors.episode_required');
        }

        if ($input->type === ContentRequestType::Translation && ($input->audioLanguage === null || $input->translationType === null)) {
            throw new ContentRequestActionException('requests.errors.translation_required');
        }

        if ($input->type === ContentRequestType::Subtitles && $input->subtitleLanguage === null) {
            throw new ContentRequestActionException('requests.errors.subtitle_required');
        }

        if ($input->type === ContentRequestType::QualityUpgrade) {
            $qualities = (array) config('playback.supported_qualities', []);

            if ($input->requestedQuality === null
                || ! in_array($input->requestedQuality, $qualities, true)
                || ($input->currentQuality !== null && ! in_array($input->currentQuality, $qualities, true))) {
                throw new ContentRequestActionException('requests.errors.invalid_quality');
            }
        }

        if (in_array($input->type, [ContentRequestType::MetadataCorrection, ContentRequestType::EpisodeListCorrection], true)
            && ($input->correctionField === null
                || ! in_array($input->correctionField, (array) config('content-requests.correction_fields', []), true)
                || $input->proposedValue === null)) {
            throw new ContentRequestActionException('requests.errors.correction_required');
        }

        if ($input->type === ContentRequestType::Other && mb_strlen((string) $input->explanation) < 20) {
            throw new ContentRequestActionException('requests.errors.explanation_required');
        }
    }

    private function assertTarget(ContentRequestInput $input): void
    {
        if ($input->type->requiresCatalogTitle() && $input->catalogTitleId === null) {
            throw new ContentRequestActionException('requests.errors.target_required');
        }

        if ($input->catalogTitleId === null) {
            return;
        }

        $title = CatalogTitle::query()->availableTo(null)->find($input->catalogTitleId);

        if ($title === null) {
            throw new ContentRequestActionException('requests.errors.invalid_target');
        }

        if ($input->seasonId !== null) {
            $season = Season::query()->availableTo(null)->where('catalog_title_id', $title->id)->find($input->seasonId);

            if ($season === null) {
                throw new ContentRequestActionException('requests.errors.invalid_target');
            }
        }

        if ($input->episodeId !== null) {
            $episode = Episode::query()
                ->availableTo(null)
                ->whereHas('season', fn ($query) => $query->where('catalog_title_id', $title->id))
                ->find($input->episodeId);

            if ($episode === null || ($input->seasonId !== null && $episode->season_id !== $input->seasonId)) {
                throw new ContentRequestActionException('requests.errors.invalid_target');
            }
        }
    }

    private function assertLanguages(ContentRequestInput $input): void
    {
        $allowed = (array) config('content-requests.language_codes', []);

        foreach ([$input->contentLocale, $input->originalLanguage, $input->audioLanguage, $input->subtitleLanguage] as $language) {
            if ($language !== null && ! in_array(Str::lower($language), $allowed, true)) {
                throw new ContentRequestActionException('requests.errors.invalid_language');
            }
        }

        if ($input->translationType !== null
            && ! in_array($input->translationType, (array) config('content-requests.translation_types', []), true)) {
            throw new ContentRequestActionException('requests.errors.invalid_translation_type');
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
