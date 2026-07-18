<?php

declare(strict_types=1);

namespace App\DTOs\Help;

final readonly class HelpCategoryData
{
    /**
     * @param  list<self>  $children
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public string $locale,
        public bool $usesFallback,
        public string $slug,
        public string $title,
        public string $description,
        public string $url,
        public int $articleCount,
        public array $children = [],
    ) {}

    /** @return array<string, mixed> */
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
            'description' => $this->description,
            'url' => $this->url,
            'article_count' => $this->articleCount,
            'children' => array_map(
                static fn (self $child): array => $child->toCacheSnapshot(),
                $this->children,
            ),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromCacheSnapshot(array $snapshot): self
    {
        $children = is_array($snapshot['children'] ?? null)
            ? array_values(array_map(
                static fn (array $child): self => self::fromCacheSnapshot($child),
                array_filter($snapshot['children'], 'is_array'),
            ))
            : [];

        return new self(
            id: (int) ($snapshot['id'] ?? 0),
            publicId: (string) ($snapshot['public_id'] ?? ''),
            code: (string) ($snapshot['code'] ?? ''),
            locale: (string) ($snapshot['locale'] ?? ''),
            usesFallback: (bool) ($snapshot['uses_fallback'] ?? false),
            slug: (string) ($snapshot['slug'] ?? ''),
            title: (string) ($snapshot['title'] ?? ''),
            description: (string) ($snapshot['description'] ?? ''),
            url: (string) ($snapshot['url'] ?? ''),
            articleCount: (int) ($snapshot['article_count'] ?? 0),
            children: $children,
        );
    }
}
