<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\SourcePage;
use App\Models\Taxonomy;
use App\Services\Crawler\PoliteHttpClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SeasonvarCatalogImporter
{
    public function __construct(
        private readonly SeasonvarSource $seasonvarSource,
        private readonly SeasonvarDiscovery $discovery,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarCatalogParser $parser,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<string>
     */
    public function discover(int $limit, ?callable $progress = null): array
    {
        $source = $this->seasonvarSource->current();
        $limit = max(1, $limit);

        $this->report($progress, 'source-ready', [
            'source_id' => $source->id,
            'code' => $source->code,
            'base_url' => $source->base_url,
            'sitemap_url' => $this->seasonvarSource->sitemapUrl(),
            'crawl_delay_seconds' => (int) $source->crawl_delay_seconds,
        ]);

        return $this->discovery->discoverFromSitemap(
            $this->seasonvarSource->sitemapUrl(),
            $limit,
            (int) $source->crawl_delay_seconds,
            $progress,
        );
    }

    /**
     * @param  list<string>  $urls
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function storeDiscoveredUrls(array $urls, ?callable $progress = null): int
    {
        $source = $this->seasonvarSource->current();
        $stored = 0;
        $total = count($urls);

        $this->report($progress, 'store-discovered-urls-started', [
            'source_id' => $source->id,
            'total' => $total,
        ]);

        foreach ($urls as $index => $url) {
            $urlHash = $this->seasonvarUrl->hash($url);
            $page = SourcePage::query()->firstOrNew(['url_hash' => $urlHash]);
            $wasExisting = $page->exists;
            $page->fill([
                'source_id' => $source->id,
                'url' => $url,
                'page_type' => $this->seasonvarUrl->pageType($url),
                'discovered_from_url' => $this->seasonvarSource->sitemapUrl(),
            ]);

            if (! $page->exists) {
                $page->parse_status = 'pending';
                $stored++;
            }

            $page->save();

            $this->report($progress, $wasExisting ? 'source-page-updated' : 'source-page-created', [
                'index' => $index + 1,
                'total' => $total,
                'source_page_id' => $page->id,
                'page_type' => $page->page_type,
                'parse_status' => $page->parse_status,
                'url_hash' => $urlHash,
                'url' => $url,
            ]);
        }

        $this->report($progress, 'store-discovered-urls-complete', [
            'total' => $total,
            'stored' => $stored,
            'updated' => $total - $stored,
        ]);

        return $stored;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return Collection<int, SourcePage>
     */
    public function pagesForArgument(mixed $argument, int $limit, ?callable $progress = null): Collection
    {
        $limit = max(1, $limit);

        if ($argument === null) {
            $this->report($progress, 'page-selection-started', [
                'mode' => 'pending',
                'limit' => $limit,
            ]);

            return $this->pendingPages($limit, $progress);
        }

        if (is_numeric($argument)) {
            $pages = SourcePage::query()
                ->with('source')
                ->whereKey((int) $argument)
                ->get();

            $this->report($progress, 'page-selection-complete', [
                'mode' => 'id',
                'argument' => $argument,
                'selected' => $pages->count(),
            ]);

            foreach ($pages as $page) {
                $this->report($progress, 'source-page-selected', [
                    'source_page_id' => $page->id,
                    'page_type' => $page->page_type,
                    'parse_status' => $page->parse_status,
                    'http_status' => $page->http_status,
                    'url' => $page->url,
                ]);
            }

            return $pages;
        }

        try {
            $url = $this->seasonvarUrl->normalize((string) $argument);
        } catch (Throwable $exception) {
            $this->report($progress, 'url-invalid', [
                'argument' => $argument,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }

        $this->report($progress, 'url-normalized', [
            'argument' => $argument,
            'url' => $url,
        ]);

        if (! $this->seasonvarUrl->isAllowed($url)) {
            $this->report($progress, 'url-blocked', [
                'url' => $url,
            ]);

            return collect();
        }

        $source = $this->seasonvarSource->current();
        $urlHash = $this->seasonvarUrl->hash($url);

        $page = SourcePage::query()->updateOrCreate(
            ['url_hash' => $urlHash],
            [
                'source_id' => $source->id,
                'url' => $url,
                'page_type' => $this->seasonvarUrl->pageType($url),
                'parse_status' => 'pending',
                'discovered_from_url' => $this->seasonvarSource->sitemapUrl(),
            ],
        );
        $page->load('source');

        $this->report($progress, $page->wasRecentlyCreated ? 'source-page-created' : 'source-page-updated', [
            'mode' => 'url-argument',
            'source_page_id' => $page->id,
            'page_type' => $page->page_type,
            'parse_status' => $page->parse_status,
            'url_hash' => $urlHash,
            'url' => $url,
        ]);

        return collect([$page]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return Collection<int, SourcePage>
     */
    public function pendingPages(int $limit, ?callable $progress = null): Collection
    {
        $limit = max(1, $limit);

        $this->report($progress, 'pending-pages-query-started', [
            'limit' => $limit,
            'parse_status' => 'pending',
            'page_type' => 'serial',
        ]);

        $pages = SourcePage::query()
            ->with('source')
            ->where('parse_status', 'pending')
            ->where('page_type', 'serial')
            ->oldest()
            ->limit($limit)
            ->get();

        $this->report($progress, 'pending-pages-query-complete', [
            'selected' => $pages->count(),
        ]);

        foreach ($pages as $page) {
            $this->report($progress, 'source-page-selected', [
                'source_page_id' => $page->id,
                'page_type' => $page->page_type,
                'parse_status' => $page->parse_status,
                'http_status' => $page->http_status,
                'last_crawled_at' => $page->last_crawled_at,
                'url' => $page->url,
            ]);
        }

        return $pages;
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{parsed: int, failed: int, failures: list<string>}
     */
    public function parsePages(Collection $pages, ?callable $progress = null): array
    {
        $parsed = 0;
        $failed = 0;
        $failures = [];
        $total = $pages->count();

        $this->report($progress, 'parse-batch-started', [
            'total' => $total,
        ]);

        $position = 0;

        foreach ($pages as $page) {
            $position++;

            $this->report($progress, 'parse-batch-item-started', [
                'index' => $position,
                'total' => $total,
                'source_page_id' => $page->id,
                'url' => $page->url,
            ]);

            try {
                $this->parsePage($page, $progress);
                $parsed++;

                $this->report($progress, 'parse-batch-item-complete', [
                    'index' => $position,
                    'total' => $total,
                    'source_page_id' => $page->id,
                    'parsed' => $parsed,
                    'failed' => $failed,
                ]);
            } catch (Throwable $exception) {
                $page->update([
                    'parse_status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'last_crawled_at' => now(),
                ]);
                $failed++;
                $failures[] = "{$page->url} ({$exception->getMessage()})";

                $this->report($progress, 'parse-batch-item-failed', [
                    'index' => $position,
                    'total' => $total,
                    'source_page_id' => $page->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'parsed' => $parsed,
                    'failed' => $failed,
                    'url' => $page->url,
                ]);
            }
        }

        $this->report($progress, 'parse-batch-complete', [
            'total' => $total,
            'parsed' => $parsed,
            'failed' => $failed,
        ]);

        return [
            'parsed' => $parsed,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function parsePage(SourcePage $page, ?callable $progress = null): void
    {
        $source = $page->source;
        $crawlDelaySeconds = (int) $source->crawl_delay_seconds;

        $this->report($progress, 'page-parse-started', [
            'source_page_id' => $page->id,
            'source_id' => $source->id,
            'page_type' => $page->page_type,
            'parse_status' => $page->parse_status,
            'crawl_delay_seconds' => $crawlDelaySeconds,
            'url' => $page->url,
        ]);

        $response = $this->httpClient->get($page->url, $crawlDelaySeconds, $progress);
        $body = $response->body();
        $contentHash = hash('sha256', $body);
        $contentChanged = $page->content_hash !== $contentHash;

        $this->report($progress, 'page-response-received', [
            'source_page_id' => $page->id,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'body_bytes' => mb_strlen($body, '8bit'),
            'content_hash' => $contentHash,
            'content_changed' => $contentChanged,
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
        ]);

        $page->update([
            'http_status' => $response->status(),
            'content_hash' => $contentHash,
            'etag' => $response->header('ETag'),
            'last_modified_header' => $response->header('Last-Modified'),
            'last_crawled_at' => now(),
            'last_changed_at' => $contentChanged ? now() : $page->last_changed_at,
        ]);

        $this->report($progress, 'source-page-crawl-metadata-updated', [
            'source_page_id' => $page->id,
            'http_status' => $page->http_status,
            'content_hash' => $page->content_hash,
            'content_changed' => $contentChanged,
            'last_crawled_at' => $page->last_crawled_at,
            'last_changed_at' => $page->last_changed_at,
        ]);

        if (! $response->successful()) {
            $page->update([
                'parse_status' => 'failed',
                'error_message' => 'HTTP '.$response->status(),
            ]);

            $this->report($progress, 'page-parse-failed', [
                'source_page_id' => $page->id,
                'http_status' => $response->status(),
                'url' => $page->url,
            ]);

            throw new RuntimeException('HTTP '.$response->status());
        }

        $this->report($progress, 'html-parse-started', [
            'source_page_id' => $page->id,
            'url' => $page->url,
        ]);

        $data = $this->parser->parse($body, $page->url);

        $this->report($progress, 'html-parse-complete', [
            'source_page_id' => $page->id,
            'title' => $data['title'],
            'original_title' => $data['original_title'],
            'type' => $data['type'],
            'year' => $data['year'],
            'external_id' => $data['external_id'],
            'poster_url' => $data['poster_url'],
            'seasons' => count($data['seasons']),
            'episodes' => count($data['episodes']),
            'taxonomies' => count($data['taxonomies']),
        ]);

        $catalogTitle = $this->upsertCatalogTitle($page, $data, $contentHash, $progress);
        $this->syncTaxonomies($catalogTitle, $data['taxonomies'], $progress);
        $seasons = $this->syncSeasons($catalogTitle, $page, $data['seasons'], $progress);
        $this->syncEpisodes($seasons, $page, $data['episodes'], $progress);

        $page->update([
            'parse_status' => 'parsed',
            'error_message' => null,
        ]);

        $this->report($progress, 'page-parse-complete', [
            'source_page_id' => $page->id,
            'catalog_title_id' => $catalogTitle->id,
            'title' => $catalogTitle->title,
            'slug' => $catalogTitle->slug,
            'url' => $page->url,
        ]);
    }

    /**
     * @param array{
     *     title: string,
     *     original_title: string|null,
     *     type: string,
     *     year: int|null,
     *     description: string|null,
     *     poster_url: string|null,
     *     external_id: string|null,
     *     current_season_number: int,
     *     seasons: list<array{number: int, title: string|null, source_url: string|null}>,
     *     episodes: list<array{season_number: int, number: int, title: string|null, source_url: string|null}>,
     *     taxonomies: list<array{type: string, name: string, source_url: string|null}>
     * } $data
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function upsertCatalogTitle(SourcePage $page, array $data, string $contentHash, ?callable $progress = null): CatalogTitle
    {
        $sourceUrlHash = $this->seasonvarUrl->hash($page->url);
        $catalogTitle = $this->findExistingCatalogTitle($page, $data['type'], $data['title'])
            ?? CatalogTitle::query()->firstOrNew([
                'source_id' => $page->source_id,
                'source_url_hash' => $sourceUrlHash,
            ]);
        $wasExisting = $catalogTitle->exists;

        $this->report($progress, 'catalog-title-upsert-started', [
            'source_page_id' => $page->id,
            'source_id' => $page->source_id,
            'existing' => $wasExisting,
            'source_url_hash' => $sourceUrlHash,
            'title' => $data['title'],
        ]);

        if (! $catalogTitle->exists || Str::contains($catalogTitle->slug, ['seasonvar', 'smotret'])) {
            $catalogTitle->slug = $this->uniqueSlug($data['title'], $data['external_id'], $sourceUrlHash, $catalogTitle->id);

            $this->report($progress, 'catalog-title-slug-prepared', [
                'source_page_id' => $page->id,
                'catalog_title_id' => $catalogTitle->id,
                'slug' => $catalogTitle->slug,
            ]);
        }

        $catalogTitle->fill([
            'source_page_id' => $catalogTitle->source_page_id ?? $page->id,
            'external_id' => $catalogTitle->external_id ?? $data['external_id'],
            'title' => $catalogTitle->exists
                ? $this->preferredTitle($catalogTitle->title, $data['title'])
                : $data['title'],
            'original_title' => $catalogTitle->original_title ?? $this->normalizedOriginalTitle($data['original_title']),
            'type' => $data['type'],
            'year' => $this->earliestYear($catalogTitle->year, $data['year']),
            'description' => $catalogTitle->description ?: $data['description'],
            'poster_url' => $catalogTitle->poster_url ?: $data['poster_url'],
            'source_url' => $catalogTitle->source_url ?: $page->url,
            'source_url_hash' => $catalogTitle->source_url_hash ?: $sourceUrlHash,
            'content_hash' => $contentHash,
            'is_published' => true,
            'indexed_at' => now(),
        ]);
        $catalogTitle->save();

        $this->report($progress, $wasExisting ? 'catalog-title-updated' : 'catalog-title-created', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'slug' => $catalogTitle->slug,
            'title' => $catalogTitle->title,
            'year' => $catalogTitle->year,
            'external_id' => $catalogTitle->external_id,
            'content_hash' => $catalogTitle->content_hash,
        ]);

        return $catalogTitle;
    }

    private function findExistingCatalogTitle(SourcePage $page, string $type, string $title): ?CatalogTitle
    {
        $titleKey = $this->normalizedSeriesTitleKey($title);
        $seriesTitle = $this->seriesTitleKey($title);

        return CatalogTitle::query()
            ->where('source_id', $page->source_id)
            ->where('type', $type)
            ->where(function ($query) use ($title, $seriesTitle): void {
                $query->where('title', $title)
                    ->orWhere('title', $seriesTitle)
                    ->orWhere('title', 'like', $seriesTitle.'/%');
            })
            ->orderBy('id')
            ->get()
            ->first(fn (CatalogTitle $catalogTitle): bool => $this->normalizedSeriesTitleKey($catalogTitle->title) === $titleKey);
    }

    private function earliestYear(?int $currentYear, ?int $incomingYear): ?int
    {
        return collect([$currentYear, $incomingYear])
            ->filter()
            ->min();
    }

    private function preferredTitle(string $currentTitle, string $incomingTitle): string
    {
        if ($this->normalizedSeriesTitleKey($currentTitle) !== $this->normalizedSeriesTitleKey($incomingTitle)) {
            return $incomingTitle;
        }

        return Str::length($currentTitle) <= Str::length($incomingTitle)
            ? $currentTitle
            : $incomingTitle;
    }

    private function normalizedOriginalTitle(?string $originalTitle): ?string
    {
        if ($originalTitle === null || $this->containsCyrillic($originalTitle)) {
            return null;
        }

        return $originalTitle;
    }

    private function normalizedSeriesTitleKey(string $title): string
    {
        return Str::lower($this->seriesTitleKey($title));
    }

    private function seriesTitleKey(string $title): string
    {
        $title = Str::squish($title);
        $parts = explode('/', $title, 2);

        if (count($parts) === 2 && $this->containsCyrillic($parts[0]) && $this->containsCyrillic($parts[1])) {
            return Str::squish($parts[0]);
        }

        return $title;
    }

    private function containsCyrillic(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1;
    }

    private function uniqueSlug(string $title, ?string $externalId, string $sourceUrlHash, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'title-'.($externalId ?: Str::substr($sourceUrlHash, 0, 12));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (CatalogTitle::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  list<array{type: string, name: string, source_url: string|null}>  $taxonomies
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncTaxonomies(CatalogTitle $catalogTitle, array $taxonomies, ?callable $progress = null): void
    {
        $ids = [];

        $this->report($progress, 'taxonomy-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'total' => count($taxonomies),
        ]);

        foreach ($taxonomies as $index => $item) {
            $slug = Str::slug($item['name']) ?: Str::substr(hash('sha256', $item['type'].'|'.$item['name']), 0, 16);

            $taxonomy = Taxonomy::query()->updateOrCreate(
                ['type' => $item['type'], 'slug' => $slug],
                ['name' => $item['name'], 'source_url' => $item['source_url']],
            );

            $ids[] = $taxonomy->id;

            $this->report($progress, $taxonomy->wasRecentlyCreated ? 'taxonomy-created' : 'taxonomy-updated', [
                'index' => $index + 1,
                'total' => count($taxonomies),
                'taxonomy_id' => $taxonomy->id,
                'catalog_title_id' => $catalogTitle->id,
                'type' => $taxonomy->type,
                'name' => $taxonomy->name,
                'slug' => $taxonomy->slug,
                'source_url' => $taxonomy->source_url,
            ]);
        }

        $catalogTitle->taxonomies()->sync($ids);

        $this->report($progress, 'taxonomy-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'synced' => count($ids),
            'taxonomy_ids' => $ids,
        ]);
    }

    /**
     * @param  list<array{number: int, title: string|null, source_url: string|null}>  $seasons
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<int, Season>
     */
    private function syncSeasons(CatalogTitle $catalogTitle, SourcePage $page, array $seasons, ?callable $progress = null): array
    {
        $syncedSeasons = [];

        $this->report($progress, 'season-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'total' => count($seasons),
        ]);

        foreach ($seasons as $index => $season) {
            $syncedSeason = Season::query()->updateOrCreate(
                [
                    'catalog_title_id' => $catalogTitle->id,
                    'number' => $season['number'],
                ],
                [
                    'source_page_id' => $page->id,
                    'title' => $season['title'],
                    'source_url' => $season['source_url'],
                    'source_url_hash' => $season['source_url'] ? $this->seasonvarUrl->hash($season['source_url']) : null,
                ],
            );

            $this->report($progress, $syncedSeason->wasRecentlyCreated ? 'season-created' : 'season-updated', [
                'index' => $index + 1,
                'total' => count($seasons),
                'season_id' => $syncedSeason->id,
                'catalog_title_id' => $catalogTitle->id,
                'source_page_id' => $page->id,
                'number' => $syncedSeason->number,
                'title' => $syncedSeason->title,
                'source_url' => $syncedSeason->source_url,
                'source_url_hash' => $syncedSeason->source_url_hash,
            ]);

            $syncedSeasons[(int) $syncedSeason->number] = $syncedSeason;
        }

        $this->report($progress, 'season-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'synced' => count($seasons),
        ]);

        return $syncedSeasons;
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array{season_number: int, number: int, title: string|null, source_url: string|null}>  $episodes
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncEpisodes(array $seasons, SourcePage $page, array $episodes, ?callable $progress = null): void
    {
        $this->report($progress, 'episode-sync-started', [
            'source_page_id' => $page->id,
            'total' => count($episodes),
        ]);

        foreach ($episodes as $index => $episode) {
            $season = $seasons[$episode['season_number']] ?? null;

            if ($season === null) {
                $this->report($progress, 'episode-sync-skipped', [
                    'index' => $index + 1,
                    'total' => count($episodes),
                    'source_page_id' => $page->id,
                    'season_number' => $episode['season_number'],
                    'episode_number' => $episode['number'],
                    'reason' => 'сезон не найден',
                ]);

                continue;
            }

            $syncedEpisode = Episode::query()->updateOrCreate(
                [
                    'season_id' => $season->id,
                    'number' => $episode['number'],
                ],
                [
                    'source_page_id' => $page->id,
                    'title' => $episode['title'],
                    'source_url' => $episode['source_url'],
                    'source_url_hash' => $episode['source_url'] ? $this->seasonvarUrl->hash($episode['source_url']) : null,
                ],
            );

            $this->report($progress, $syncedEpisode->wasRecentlyCreated ? 'episode-created' : 'episode-updated', [
                'index' => $index + 1,
                'total' => count($episodes),
                'episode_id' => $syncedEpisode->id,
                'season_id' => $season->id,
                'source_page_id' => $page->id,
                'season_number' => $episode['season_number'],
                'number' => $syncedEpisode->number,
                'title' => $syncedEpisode->title,
                'source_url' => $syncedEpisode->source_url,
            ]);
        }

        $this->report($progress, 'episode-sync-complete', [
            'source_page_id' => $page->id,
            'synced' => count($episodes),
        ]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
