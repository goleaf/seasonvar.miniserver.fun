<?php

declare(strict_types=1);

namespace App\DTOs\CollectionQuality;

final readonly class CatalogCollectionQualityFacts
{
    public function __construct(
        public int $collectionId,
        public int $contentVersion,
        public string $name,
        public ?string $description,
        public bool $categoryPresent,
        public bool $categoryActive,
        public int $itemCount,
        public int $watchableItemCount,
        public int $averageThemeMatch,
        public int $preciseReasonCount,
        public int $reportCount,
        public int $saveCount,
        public int $completionCount,
        public int $returnCount,
        public ?int $exactDuplicateCollectionId,
        public ?int $similarTextCollectionId,
        public int $repeatedTextCount,
        public bool $sourceManaged,
        public bool $editoriallyVerifiedCurrent,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            collectionId: max(0, (int) ($data['collection_id'] ?? 0)),
            contentVersion: max(1, (int) ($data['content_version'] ?? 1)),
            name: trim((string) ($data['name'] ?? '')),
            description: self::nullableString($data['description'] ?? null),
            categoryPresent: (bool) ($data['category_present'] ?? false),
            categoryActive: (bool) ($data['category_active'] ?? false),
            itemCount: max(0, (int) ($data['item_count'] ?? 0)),
            watchableItemCount: max(0, (int) ($data['watchable_item_count'] ?? 0)),
            averageThemeMatch: self::percentage($data['average_theme_match'] ?? 0),
            preciseReasonCount: max(0, (int) ($data['precise_reason_count'] ?? 0)),
            reportCount: max(0, (int) ($data['report_count'] ?? 0)),
            saveCount: max(0, (int) ($data['save_count'] ?? 0)),
            completionCount: max(0, (int) ($data['completion_count'] ?? 0)),
            returnCount: max(0, (int) ($data['return_count'] ?? 0)),
            exactDuplicateCollectionId: self::nullablePositiveInteger(
                $data['exact_duplicate_collection_id'] ?? null,
            ),
            similarTextCollectionId: self::nullablePositiveInteger(
                $data['similar_text_collection_id'] ?? null,
            ),
            repeatedTextCount: max(1, (int) ($data['repeated_text_count'] ?? 1)),
            sourceManaged: (bool) ($data['source_managed'] ?? false),
            editoriallyVerifiedCurrent: (bool) ($data['editorially_verified_current'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'collection_id' => $this->collectionId,
            'content_version' => $this->contentVersion,
            'name' => $this->name,
            'description' => $this->description,
            'category_present' => $this->categoryPresent,
            'category_active' => $this->categoryActive,
            'item_count' => $this->itemCount,
            'watchable_item_count' => $this->watchableItemCount,
            'average_theme_match' => $this->averageThemeMatch,
            'precise_reason_count' => $this->preciseReasonCount,
            'report_count' => $this->reportCount,
            'save_count' => $this->saveCount,
            'completion_count' => $this->completionCount,
            'return_count' => $this->returnCount,
            'exact_duplicate_collection_id' => $this->exactDuplicateCollectionId,
            'similar_text_collection_id' => $this->similarTextCollectionId,
            'repeated_text_count' => $this->repeatedTextCount,
            'source_managed' => $this->sourceManaged,
            'editorially_verified_current' => $this->editoriallyVerifiedCurrent,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private static function nullablePositiveInteger(mixed $value): ?int
    {
        $integer = (int) ($value ?? 0);

        return $integer > 0 ? $integer : null;
    }

    private static function percentage(mixed $value): int
    {
        return min(100, max(0, (int) $value));
    }
}
