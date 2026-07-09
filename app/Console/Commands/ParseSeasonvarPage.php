<?php

namespace App\Console\Commands;

use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\Taxonomy;
use App\Services\Crawler\PoliteHttpClient;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

#[Signature('seasonvar:parse-page {url? : A URL, source_pages id, or empty to parse pending pages} {--limit=25 : Maximum pending pages to parse}')]
#[Description('Fetch and parse allowed Seasonvar catalog metadata pages')]
class ParseSeasonvarPage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        PoliteHttpClient $httpClient,
        SeasonvarCatalogParser $parser,
        SeasonvarUrl $seasonvarUrl,
    ): int {
        $pages = $this->pages($seasonvarUrl);

        if ($pages->isEmpty()) {
            $this->info('No source pages to parse.');

            return self::SUCCESS;
        }

        $parsed = 0;
        $failed = 0;

        foreach ($pages as $page) {
            try {
                $this->parseOne($page, $httpClient, $parser, $seasonvarUrl);
                $parsed++;
                $this->line("Parsed: {$page->url}");
            } catch (Throwable $exception) {
                $page->update([
                    'parse_status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'last_crawled_at' => now(),
                ]);
                $failed++;
                $this->warn("Failed: {$page->url} ({$exception->getMessage()})");
            }
        }

        $this->info("Done. Parsed: {$parsed}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, SourcePage>
     */
    private function pages(SeasonvarUrl $seasonvarUrl): Collection
    {
        $argument = $this->argument('url');

        if ($argument === null) {
            return SourcePage::query()
                ->where('parse_status', 'pending')
                ->whereIn('page_type', ['serial', 'unknown'])
                ->oldest()
                ->limit(max(1, (int) $this->option('limit')))
                ->get();
        }

        if (is_numeric($argument)) {
            return SourcePage::query()->whereKey((int) $argument)->get();
        }

        $url = $seasonvarUrl->normalize((string) $argument);

        if (! $seasonvarUrl->isAllowed($url)) {
            $this->error('The URL is not an allowed metadata page.');

            return collect();
        }

        $source = Source::query()->firstOrCreate(
            ['code' => 'seasonvar'],
            [
                'name' => 'Seasonvar Metadata',
                'base_url' => 'https://seasonvar.net',
                'is_active' => true,
                'crawl_delay_seconds' => 3,
            ],
        );

        $page = SourcePage::query()->updateOrCreate(
            ['url_hash' => $seasonvarUrl->hash($url)],
            [
                'source_id' => $source->id,
                'url' => $url,
                'page_type' => $seasonvarUrl->pageType($url),
                'parse_status' => 'pending',
            ],
        );

        return collect([$page]);
    }

    private function parseOne(
        SourcePage $page,
        PoliteHttpClient $httpClient,
        SeasonvarCatalogParser $parser,
        SeasonvarUrl $seasonvarUrl,
    ): void {
        $source = $page->source;
        $response = $httpClient->get($page->url, (int) $source->crawl_delay_seconds);
        $contentHash = hash('sha256', $response->body());

        $page->update([
            'http_status' => $response->status(),
            'content_hash' => $contentHash,
            'etag' => $response->header('ETag'),
            'last_modified_header' => $response->header('Last-Modified'),
            'last_crawled_at' => now(),
            'last_changed_at' => $page->content_hash !== $contentHash ? now() : $page->last_changed_at,
        ]);

        if (! $response->successful()) {
            $page->update([
                'parse_status' => 'failed',
                'error_message' => 'HTTP '.$response->status(),
            ]);

            return;
        }

        $data = $parser->parse($response->body(), $page->url);
        $catalogTitle = $this->upsertCatalogTitle($page, $data, $contentHash, $seasonvarUrl);
        $this->syncTaxonomies($catalogTitle, $data['taxonomies']);
        $this->syncSeasons($catalogTitle, $page, $data['seasons'], $seasonvarUrl);

        $page->update([
            'parse_status' => 'parsed',
            'error_message' => null,
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
     *     external_id: string|null
     * } $data
     */
    private function upsertCatalogTitle(SourcePage $page, array $data, string $contentHash, SeasonvarUrl $seasonvarUrl): CatalogTitle
    {
        $sourceUrlHash = $seasonvarUrl->hash($page->url);
        $catalogTitle = CatalogTitle::query()->firstOrNew([
            'source_id' => $page->source_id,
            'source_url_hash' => $sourceUrlHash,
        ]);

        if (! $catalogTitle->exists) {
            $catalogTitle->slug = $this->uniqueSlug($data['title'], $data['external_id'], $sourceUrlHash);
        }

        $catalogTitle->fill([
            'source_page_id' => $page->id,
            'external_id' => $data['external_id'],
            'title' => $data['title'],
            'original_title' => $data['original_title'],
            'type' => $data['type'],
            'year' => $data['year'],
            'description' => $data['description'],
            'poster_url' => $data['poster_url'],
            'source_url' => $page->url,
            'content_hash' => $contentHash,
            'is_published' => true,
            'indexed_at' => now(),
        ]);
        $catalogTitle->save();

        return $catalogTitle;
    }

    private function uniqueSlug(string $title, ?string $externalId, string $sourceUrlHash): string
    {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'title-'.($externalId ?: substr($sourceUrlHash, 0, 12));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (CatalogTitle::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  list<array{type: string, name: string, source_url: string|null}>  $taxonomies
     */
    private function syncTaxonomies(CatalogTitle $catalogTitle, array $taxonomies): void
    {
        $ids = [];

        foreach ($taxonomies as $item) {
            $slug = Str::slug($item['name']) ?: substr(hash('sha256', $item['type'].'|'.$item['name']), 0, 16);

            $taxonomy = Taxonomy::query()->updateOrCreate(
                ['type' => $item['type'], 'slug' => $slug],
                ['name' => $item['name'], 'source_url' => $item['source_url']],
            );

            $ids[] = $taxonomy->id;
        }

        $catalogTitle->taxonomies()->sync($ids);
    }

    /**
     * @param  list<array{number: int, title: string|null, source_url: string|null}>  $seasons
     */
    private function syncSeasons(CatalogTitle $catalogTitle, SourcePage $page, array $seasons, SeasonvarUrl $seasonvarUrl): void
    {
        foreach ($seasons as $season) {
            Season::query()->updateOrCreate(
                [
                    'catalog_title_id' => $catalogTitle->id,
                    'number' => $season['number'],
                ],
                [
                    'source_page_id' => $page->id,
                    'title' => $season['title'],
                    'source_url' => $season['source_url'],
                    'source_url_hash' => $season['source_url'] ? $seasonvarUrl->hash($season['source_url']) : null,
                ],
            );
        }
    }
}
