<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use App\Models\CatalogTitle;

final readonly class SeasonvarPageHandlerResult
{
    /**
     * @param  list<string>  $structuredFields
     * @param  list<string>  $missingDataFlags
     */
    public function __construct(
        public ?CatalogTitle $catalogTitle = null,
        public int $mediaAttached = 0,
        public int $mediaUpdated = 0,
        public int $mediaSkipped = 0,
        public int $mediaFailed = 0,
        public int $linkedSerialUrls = 0,
        public int $taxonomiesCreated = 0,
        public int $taxonomiesUpdated = 0,
        public int $duplicatesPrevented = 0,
        public array $structuredFields = [],
        public array $missingDataFlags = [],
    ) {}

    /**
     * @return array{catalog_title: CatalogTitle|null, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function toLegacyResult(): array
    {
        return [
            'catalog_title' => $this->catalogTitle,
            'media_attached' => $this->mediaAttached,
            'media_updated' => $this->mediaUpdated,
            'media_skipped' => $this->mediaSkipped,
            'media_failed' => $this->mediaFailed,
        ];
    }
}
