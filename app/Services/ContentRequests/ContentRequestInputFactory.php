<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentRequestType;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Support\PlainText;
use Illuminate\Support\Str;

final readonly class ContentRequestInputFactory
{
    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    /** @param array<string, mixed> $data */
    public function from(array $data): ContentRequestInput
    {
        $type = ContentRequestType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null) {
            throw new ContentRequestActionException('requests.errors.invalid_type');
        }

        $title = $this->normalizer->display(PlainText::clean($data['title'] ?? '', 240));
        $sourceLinks = collect((array) ($data['source_links'] ?? []))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->values()
            ->all();
        $externalIdentifiers = collect((array) ($data['external_identifiers'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['identifier'] ?? '')) !== '')
            ->map(fn (array $item): array => [
                'provider' => Str::lower(trim((string) ($item['provider'] ?? ''))),
                'identifier' => trim((string) ($item['identifier'] ?? '')),
            ])->values()->all();

        return new ContentRequestInput(
            type: $type,
            title: $title,
            originalTitle: $this->optional($data['original_title'] ?? null, 240),
            alternativeTitle: $this->optional($data['alternative_title'] ?? null, 240),
            releaseYear: $this->positiveInt($data['release_year'] ?? null),
            country: $this->optional($data['country'] ?? null, 100),
            contentLocale: $this->code($data['content_locale'] ?? null),
            originalLanguage: $this->code($data['original_language'] ?? null),
            audioLanguage: in_array($type, [ContentRequestType::Serial, ContentRequestType::Season, ContentRequestType::Episode, ContentRequestType::Translation], true) ? $this->code($data['audio_language'] ?? null) : null,
            subtitleLanguage: in_array($type, [ContentRequestType::Serial, ContentRequestType::Season, ContentRequestType::Episode, ContentRequestType::Subtitles], true) ? $this->code($data['subtitle_language'] ?? null) : null,
            translationType: $type === ContentRequestType::Translation ? $this->code($data['translation_type'] ?? null) : null,
            translationStudio: in_array($type, [ContentRequestType::Serial, ContentRequestType::Season, ContentRequestType::Episode, ContentRequestType::Translation], true) ? $this->optional($data['translation_studio'] ?? null, 120) : null,
            catalogTitleId: in_array($type, [ContentRequestType::Serial, ContentRequestType::Other], true) ? null : $this->positiveInt($data['catalog_title_id'] ?? null),
            seasonId: in_array($type, [ContentRequestType::Season, ContentRequestType::Serial, ContentRequestType::Other], true) ? null : $this->positiveInt($data['season_id'] ?? null),
            episodeId: in_array($type, [ContentRequestType::Episode, ContentRequestType::Season, ContentRequestType::Serial, ContentRequestType::Other], true) ? null : $this->positiveInt($data['episode_id'] ?? null),
            seasonNumber: $type === ContentRequestType::Season ? $this->nonNegativeInt($data['season_number'] ?? null) : null,
            seasonKind: $type === ContentRequestType::Season ? $this->code($data['season_kind'] ?? null) : null,
            episodeNumber: $type === ContentRequestType::Episode ? $this->nonNegativeInt($data['episode_number'] ?? null) : null,
            episodeReleaseDate: $type === ContentRequestType::Episode ? $this->optional($data['episode_release_date'] ?? null, 10) : null,
            currentQuality: $type === ContentRequestType::QualityUpgrade ? $this->code($data['current_quality'] ?? null) : null,
            requestedQuality: $type === ContentRequestType::QualityUpgrade ? $this->code($data['requested_quality'] ?? null) : null,
            correctionField: $type === ContentRequestType::EpisodeListCorrection
                ? 'episode_list'
                : ($type === ContentRequestType::MetadataCorrection ? $this->code($data['correction_field'] ?? null) : null),
            currentValue: in_array($type, [ContentRequestType::MetadataCorrection, ContentRequestType::EpisodeListCorrection], true) ? $this->optionalMultiline($data['current_value'] ?? null, 2_000) : null,
            proposedValue: in_array($type, [ContentRequestType::MetadataCorrection, ContentRequestType::EpisodeListCorrection], true) ? $this->optionalMultiline($data['proposed_value'] ?? null, 4_000) : null,
            explanation: $this->optionalMultiline($data['explanation'] ?? null, 4_000),
            differentExplanation: $this->optionalMultiline($data['different_explanation'] ?? null, 1_000),
            externalIdentifiers: $externalIdentifiers,
            sourceLinks: $sourceLinks,
            submissionToken: Str::lower(trim((string) ($data['submission_token'] ?? ''))),
        );
    }

    private function optional(mixed $value, int $limit): ?string
    {
        $clean = $this->normalizer->display(PlainText::clean($value, $limit));

        return $clean !== '' ? $clean : null;
    }

    private function optionalMultiline(mixed $value, int $limit): ?string
    {
        $clean = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $clean) ?? '';
        $clean = strip_tags($clean);
        $clean = preg_replace('/[^\P{C}\n\r\t]+/u', '', $clean) ?? '';
        $clean = trim(Str::limit($clean, $limit, ''));

        return $clean !== '' ? $clean : null;
    }

    private function code(mixed $value): ?string
    {
        $clean = Str::lower(trim((string) $value));

        return $clean !== '' ? $clean : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $int === false ? null : (int) $int;
    }
}
