<?php

namespace App\Services\Catalog;

class CatalogStatsSnapshotBuilder
{
    public function __construct(
        private readonly CatalogStatsPageBuilder $statsPage,
        private readonly CatalogStatsSnapshotSanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this->sanitizer->sanitize($this->statsPage->data());
    }

    /**
     * @return array<string, mixed>
     */
    public function seo(): array
    {
        return $this->statsPage->seo();
    }
}
