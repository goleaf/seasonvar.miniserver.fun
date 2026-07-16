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
            audioLanguage: $this->code($data['audio_language'] ?? null),
            subtitleLanguage: $this->code($data['subtitle_language'] ?? null),
            translationType: $this->code($data['translation_type'] ?? null),
            translationStudio: $this->optional($data['translation_studio'] ?? null, 120),
            catalogTitleId: $this->positiveInt($data['catalog_title_id'] ?? null),
            seasonId: $this->positiveInt($data['season_id'] ?? null),
            episodeId: $this->positiveInt($data['episode_id'] ?? null),
            seasonNumber: $this->nonNegativeInt($data['season_number'] ?? null),
            seasonKind: $this->code($data['season_kind'] ?? null),
            episodeNumber: $this->nonNegativeInt($data['episode_number'] ?? null),
            episodeReleaseDate: $this->optional($data['episode_release_date'] ?? null, 10),
            currentQuality: $this->code($data['current_quality'] ?? null),
            requestedQuality: $this->code($data['requested_quality'] ?? null),
            correctionField: $this->code($data['correction_field'] ?? null),
            currentValue: $this->optionalMultiline($data['current_value'] ?? null, 2_000),
            proposedValue: $this->optionalMultiline($data['proposed_value'] ?? null, 4_000),
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
