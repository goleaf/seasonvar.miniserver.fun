<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('seasonvar:parse-page {url? : Ссылка страницы сериала seasonvar.ru} {--page-only : Разобрать только указанную страницу без найденных сезонов} {--season-limit=80 : Максимум связанных сезонов за один запуск}')]
#[Description('Разбирает одну страницу Seasonvar и найденные страницы сезонов в одну карточку каталога')]
class ParseSeasonvarPage extends Command
{
    use OutputsSeasonvarProgress;

    private const DEFAULT_URL = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';

    private const COUNT_RELATIONS = [
        'seasons',
        'episodes',
        'genres',
        'countries',
        'actors',
        'directors',
        'ageRatings',
        'translations',
        'statuses',
        'networks',
        'studios',
        'tags',
    ];

    private const CATALOG_RELATION_COUNT_ATTRIBUTES = [
        'genres_count',
        'countries_count',
        'actors_count',
        'directors_count',
        'age_ratings_count',
        'translations_count',
        'statuses_count',
        'networks_count',
        'studios_count',
        'tags_count',
    ];

    /**
     * Execute the console command.
     */
    public function handle(SeasonvarCatalogImporter $importer): int
    {
        $url = trim((string) ($this->argument('url') ?: self::DEFAULT_URL));
        $progress = $this->seasonvarProgress();
        $parsedUrls = collect();

        try {
            $catalogTitle = $this->parseUrl($importer, $url, $progress, $parsedUrls);

            if ($catalogTitle !== null && ! (bool) $this->option('page-only')) {
                $this->parseSeasonUrls($importer, $catalogTitle, $progress, $parsedUrls);
            }

            $this->callSilently('seasonvar:merge-titles');
            $catalogTitle = $this->freshCatalogTitle($catalogTitle);

            if ($catalogTitle === null) {
                $this->warn('Страница разобрана, но карточка каталога не найдена.');

                return self::FAILURE;
            }

            $this->info(sprintf(
                'Готово: %s -> %s, сезонов %d, серий %d, связей %d, страниц обработано %d.',
                $catalogTitle->title,
                route('titles.show', $catalogTitle, false),
                $catalogTitle->seasons_count,
                $catalogTitle->episodes_count,
                $this->catalogRelationCount($catalogTitle),
                $parsedUrls->count(),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, string>  $parsedUrls
     */
    private function parseUrl(SeasonvarCatalogImporter $importer, string $url, callable $progress, Collection $parsedUrls): ?CatalogTitle
    {
        $pages = $importer->pagesForArgument($url, 1, $progress);
        $page = $pages->first();

        if ($page === null) {
            return null;
        }

        $normalizedUrl = $page->url;

        if ($parsedUrls->contains($normalizedUrl)) {
            return $this->catalogTitleForPage($page);
        }

        $parsedUrls->push($normalizedUrl);
        $importer->parsePage($page, $progress);
        $page->refresh();

        return $this->catalogTitleForPage($page);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, string>  $parsedUrls
     */
    private function parseSeasonUrls(SeasonvarCatalogImporter $importer, CatalogTitle $catalogTitle, callable $progress, Collection $parsedUrls): void
    {
        $seasonLimit = max(1, (int) $this->option('season-limit'));
        $seasonUrls = $catalogTitle->fresh(['seasons'])?->seasons
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->take($seasonLimit)
            ->values() ?? collect();

        $this->line('Найдено страниц сезонов: '.$seasonUrls->count());

        foreach ($seasonUrls as $seasonUrl) {
            $this->parseUrl($importer, (string) $seasonUrl, $progress, $parsedUrls);
        }
    }

    private function catalogTitleForPage(SourcePage $page): ?CatalogTitle
    {
        return CatalogTitle::query()
            ->where('source_page_id', $page->id)
            ->orWhere(function ($query) use ($page): void {
                $query->where('source_id', $page->source_id)
                    ->where('source_url_hash', $page->url_hash);
            })
            ->first()
            ?? Season::query()
                ->with('catalogTitle')
                ->where('source_url_hash', $page->url_hash)
                ->first()
                ?->catalogTitle;
    }

    private function freshCatalogTitle(?CatalogTitle $catalogTitle): ?CatalogTitle
    {
        if ($catalogTitle === null) {
            return null;
        }

        return CatalogTitle::query()
            ->withCount(self::COUNT_RELATIONS)
            ->whereKey($catalogTitle->id)
            ->first()
            ?? CatalogTitle::query()
                ->withCount(self::COUNT_RELATIONS)
                ->where('source_id', $catalogTitle->source_id)
                ->where('title', $catalogTitle->title)
                ->oldest()
                ->first();
    }

    private function catalogRelationCount(CatalogTitle $catalogTitle): int
    {
        return collect(self::CATALOG_RELATION_COUNT_ATTRIBUTES)
            ->sum(fn (string $attribute): int => (int) $catalogTitle->getAttribute($attribute));
    }
}
