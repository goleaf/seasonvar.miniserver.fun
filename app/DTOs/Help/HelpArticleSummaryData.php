<?php

declare(strict_types=1);

namespace App\DTOs\Help;

use App\Enums\HelpArticleType;

final readonly class HelpArticleSummaryData
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public string $locale,
        public bool $usesFallback,
        public string $slug,
        public string $title,
        public string $summary,
        public string $url,
        public HelpArticleType $type,
        public string $typeLabel,
        public string $categoryCode,
        public string $categoryTitle,
        public string $categoryUrl,
        public bool $featured,
        public ?string $lastReviewedLabel,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function toCacheSnapshot(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->publicId,
            'code' => $this->code,
            'locale' => $this->locale,
            'uses_fallback' => $this->usesFallback,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'url' => $this->url,
            'type' => $this->type->value,
            'type_label' => $this->typeLabel,
            'category_code' => $this->categoryCode,
            'category_title' => $this->categoryTitle,
            'category_url' => $this->categoryUrl,
            'featured' => $this->featured,
            'last_reviewed_label' => $this->lastReviewedLabel,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromCacheSnapshot(array $snapshot): self
    {
        return new self(
            id: (int) ($snapshot['id'] ?? 0),
            publicId: (string) ($snapshot['public_id'] ?? ''),
            code: (string) ($snapshot['code'] ?? ''),
            locale: (string) ($snapshot['locale'] ?? ''),
            usesFallback: (bool) ($snapshot['uses_fallback'] ?? false),
            slug: (string) ($snapshot['slug'] ?? ''),
            title: (string) ($snapshot['title'] ?? ''),
            summary: (string) ($snapshot['summary'] ?? ''),
            url: (string) ($snapshot['url'] ?? ''),
            type: HelpArticleType::from((string) ($snapshot['type'] ?? '')),
            typeLabel: (string) ($snapshot['type_label'] ?? ''),
            categoryCode: (string) ($snapshot['category_code'] ?? ''),
            categoryTitle: (string) ($snapshot['category_title'] ?? ''),
            categoryUrl: (string) ($snapshot['category_url'] ?? ''),
            featured: (bool) ($snapshot['featured'] ?? false),
            lastReviewedLabel: is_string($snapshot['last_reviewed_label'] ?? null)
                ? $snapshot['last_reviewed_label']
                : null,
        );
    }
}
