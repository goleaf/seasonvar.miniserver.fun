<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarFetchedPage;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Enums\SeasonvarPageType;
use App\Models\SourcePage;
use InvalidArgumentException;

final class SeasonvarCatalogPagePreparer
{
    public function __construct(
        private readonly SeasonvarSourcePageFetcher $pageFetcher,
        private readonly SeasonvarCatalogParser $parser,
        private readonly SeasonvarPreparedMediaResolver $mediaResolver,
        private readonly SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function prepare(
        SourcePage $page,
        ?int $runId = null,
        ?callable $progress = null,
    ): SeasonvarPreparedCatalogPage {
        $fetched = $this->pageFetcher->fetch($page, $runId, $progress);

        return $this->prepareFetched($page, $fetched, $progress);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function prepareFetched(
        SourcePage $page,
        SeasonvarFetchedPage $fetched,
        ?callable $progress = null,
    ): SeasonvarPreparedCatalogPage {

        $parsed = SeasonvarCatalogData::fromParsed(
            $this->parser->parse($fetched->body, $page->url),
        );
        $resolvedMedia = $this->mediaResolver->resolve($parsed->media, $progress);
        $catalogData = SeasonvarCatalogData::fromParsed([
            ...$parsed->toArray(),
            'media' => $resolvedMedia['media'],
        ]);

        return new SeasonvarPreparedCatalogPage(
            sourcePageId: $page->id,
            catalogData: $catalogData,
            discoveredSeasonUrls: $this->discoveredSeasonUrls($catalogData, $page->url),
            contentHash: $fetched->contentHash,
            parserVersion: SeasonvarCatalogParser::METADATA_VERSION,
            warnings: $resolvedMedia['warnings'],
        );
    }

    /**
     * @return list<string>
     */
    private function discoveredSeasonUrls(SeasonvarCatalogData $catalogData, string $baseUrl): array
    {
        return collect($catalogData->seasons)
            ->pluck('source_url')
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(function (string $url) use ($baseUrl): ?string {
                try {
                    $normalized = $this->seasonvarUrl->normalize($url, $baseUrl);
                } catch (InvalidArgumentException) {
                    return null;
                }

                if (! $this->seasonvarUrl->isAllowed($normalized)
                    || $this->seasonvarUrl->pageType($normalized) !== SeasonvarPageType::Serial) {
                    return null;
                }

                return $normalized;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
