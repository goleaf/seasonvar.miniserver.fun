<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use Illuminate\Support\Facades\Validator;

final readonly class SeasonvarPreparedCatalogPage
{
    /**
     * @param  list<string>  $discoveredSeasonUrls
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public int $sourcePageId,
        public SeasonvarCatalogData $catalogData,
        public array $discoveredSeasonUrls,
        public string $contentHash,
        public int $parserVersion,
        public array $warnings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'source_page_id' => $this->sourcePageId,
            'catalog_data' => $this->catalogData->toArray(),
            'discovered_season_urls' => $this->discoveredSeasonUrls,
            'content_hash' => $this->contentHash,
            'parser_version' => $this->parserVersion,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $validated = Validator::make($payload, [
            'source_page_id' => ['required', 'integer', 'min:1'],
            'catalog_data' => ['required', 'array'],
            'discovered_season_urls' => ['present', 'array', 'max:10000'],
            'discovered_season_urls.*' => ['required', 'string', 'max:2048'],
            'content_hash' => ['required', 'string', 'size:64'],
            'parser_version' => ['required', 'integer', 'min:1'],
            'warnings' => ['present', 'array', 'max:10000'],
            'warnings.*.type' => ['required', 'string', 'max:128'],
        ])->validate();

        return new self(
            sourcePageId: (int) $validated['source_page_id'],
            catalogData: SeasonvarCatalogData::fromParsed($validated['catalog_data']),
            discoveredSeasonUrls: array_values($validated['discovered_season_urls']),
            contentHash: $validated['content_hash'],
            parserVersion: (int) $validated['parser_version'],
            warnings: array_values($validated['warnings']),
        );
    }
}
