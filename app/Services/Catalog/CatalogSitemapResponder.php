<?php

namespace App\Services\Catalog;

use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSchema;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CatalogSitemapResponder
{
    private const SITEMAP_PAGE_SIZE = 10000;

    private const VIDEO_SITEMAP_PAGE_SIZE = 5000;

    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogDirectoryRegistry $directories,
        private readonly CatalogCollectionQuery $collections,
        private readonly CatalogCollectionSchema $collectionSchema,
    ) {}

    public function index(): StreamedResponse
    {
        return response()->stream(function (): void {
            $titleSitemapPages = max(1, (int) ceil($this->titles->visibleTo(null)
                ->whereNotNull('slug')
                ->count() / self::SITEMAP_PAGE_SIZE));
            $videoSitemapPages = max(1, (int) ceil($this->videoSitemapMediaQuery()->count() / self::VIDEO_SITEMAP_PAGE_SIZE));

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $this->writeSitemapIndexUrl(route('sitemap.static'), now());
            $this->writeSitemapIndexUrl(route('sitemap.taxonomies'), now());
            $this->writeSitemapIndexUrl(route('sitemap.landings'), now());
            $this->writeSitemapIndexUrl(route('sitemap.collections'), now());

            for ($page = 1; $page <= $titleSitemapPages; $page++) {
                $this->writeSitemapIndexUrl(route('sitemap.titles', ['page' => $page]), now());
            }

            for ($page = 1; $page <= $videoSitemapPages; $page++) {
                $this->writeSitemapIndexUrl(route('sitemap.videos', ['page' => $page]), now());
            }

            echo '</sitemapindex>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function staticPages(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $this->writeSitemapUrl(route('home'), now(), 'daily', '1.0');
            $this->writeSitemapUrl(route('titles.index'), now(), 'daily', '0.9');
            $this->writeSitemapUrl(route('collections.index'), now(), 'daily', '0.8');

            foreach ($this->directories->all() as $directory) {
                $this->writeSitemapUrl(route($directory->indexRouteName), now(), 'weekly', '0.8');
            }

            $this->titles->visibleTo(null)
                ->select('year')
                ->whereNotNull('year')
                ->whereBetween('year', [$this->minimumYear(), $this->maximumYear()])
                ->groupBy('year')
                ->orderByDesc('year')
                ->cursor()
                ->each(function (CatalogTitle $bucket): void {
                    $this->writeSitemapUrl(route('titles.year', ['year' => (int) $bucket->year]), now(), 'weekly', '0.7');
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function taxonomies(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            foreach ($this->taxonomies->relations() as $filterType => $config) {
                $modelClass = $config['model'];

                $query = $modelClass::query()
                    ->select(['id', 'slug', 'updated_at'])
                    ->whereHas('catalogTitles', fn (Builder $query): Builder => $query->whereIn(
                        'catalog_titles.id',
                        $this->titles->visibleTo(null)->select('catalog_titles.id'),
                    ));

                if ($modelClass === Tag::class) {
                    $query->whereIn('id', Tag::query()->publiclyEligible()->select('tags.id'));
                }

                $query
                    ->orderBy('id')
                    ->chunkById(1000, function (Collection $taxonomies) use ($filterType): void {
                        foreach ($taxonomies as $taxonomy) {
                            $slug = (string) $taxonomy->getAttribute('slug');
                            $this->writeSitemapUrl(
                                route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $slug]),
                                $taxonomy->getAttribute('updated_at'),
                                'weekly',
                                '0.7',
                            );
                        }
                    });
            }

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function landings(): StreamedResponse
    {
        return response()->stream(function (): void {
            $years = $this->landingYears();

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            foreach ($this->landingFilterTypes() as $filterType) {
                $modelClass = $this->taxonomies->modelClass($filterType);

                $taxonomies = $modelClass::query()
                    ->select(['id', 'slug'])
                    ->withCount(['catalogTitles as catalog_titles_count' => fn (Builder $query): Builder => $query->whereIn(
                        'catalog_titles.id',
                        $this->titles->visibleTo(null)->select('catalog_titles.id'),
                    )])
                    ->whereHas('catalogTitles', fn (Builder $query): Builder => $query->whereIn(
                        'catalog_titles.id',
                        $this->titles->visibleTo(null)->select('catalog_titles.id'),
                    ))
                    ->orderByDesc('catalog_titles_count')
                    ->limit(80)
                    ->get();
                $yearsByTaxonomyId = $this->landingYearsByTaxonomy($filterType, $taxonomies->pluck('id'), $years);

                $taxonomies->each(function (Model $taxonomy) use ($filterType, $years, $yearsByTaxonomyId): void {
                    $taxonomyYears = $yearsByTaxonomyId->get((int) $taxonomy->getKey(), collect());

                    if ($taxonomyYears->isEmpty()) {
                        return;
                    }

                    $taxonomyYearLookup = $taxonomyYears->flip();

                    foreach ($years as $year) {
                        if (! $taxonomyYearLookup->has($year)) {
                            continue;
                        }

                        $url = route('titles.taxonomy', [
                            'type' => $filterType,
                            'taxonomy' => (string) $taxonomy->getAttribute('slug'),
                        ]).'?year='.$year;
                        $this->writeSitemapUrl($url, now(), 'weekly', '0.65');
                    }
                });
            }

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  Collection<int, int|string>  $taxonomyIds
     * @param  Collection<int, int>  $years
     * @return Collection<int, Collection<int, int>>
     */
    private function landingYearsByTaxonomy(string $filterType, Collection $taxonomyIds, Collection $years): Collection
    {
        $taxonomyIds = $taxonomyIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($taxonomyIds->isEmpty() || $years->isEmpty()) {
            return collect();
        }

        $catalogTitleRelation = (new CatalogTitle)->{$this->taxonomies->relationName($filterType)}();
        $pivotTable = $catalogTitleRelation->getTable();
        $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
        $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();

        return DB::table($pivotTable)
            ->joinSub(
                $this->titles->visibleTo(null)->select(['catalog_titles.id', 'catalog_titles.year']),
                'visible_catalog_titles',
                'visible_catalog_titles.id',
                '=',
                $pivotTable.'.'.$titlePivotKey,
            )
            ->whereIn($pivotTable.'.'.$relatedPivotKey, $taxonomyIds)
            ->whereIn('visible_catalog_titles.year', $years)
            ->select([
                $pivotTable.'.'.$relatedPivotKey.' as taxonomy_id',
                'visible_catalog_titles.year as year',
            ])
            ->groupBy($pivotTable.'.'.$relatedPivotKey, 'visible_catalog_titles.year')
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->taxonomy_id)
            ->map(fn (Collection $rows): Collection => $rows
                ->pluck('year')
                ->map(fn (mixed $year): int => (int) $year)
                ->values());
    }

    public function titles(int $page): StreamedResponse
    {
        $page = max(1, $page);

        return response()->stream(function () use ($page): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

            $this->titles->visibleTo(null)
                ->select(['id', 'slug', 'title', 'poster_url', 'updated_at', 'indexed_at'])
                ->whereNotNull('slug')
                ->orderBy('id')
                ->forPage($page, self::SITEMAP_PAGE_SIZE)
                ->get()
                ->each(function (CatalogTitle $title): void {
                    $this->writeSitemapUrlWithImage(
                        route('titles.show', $title),
                        $title->indexed_at ?: $title->updated_at,
                        'weekly',
                        '0.8',
                        $title->poster_url,
                        'Постер '.$title->title,
                    );
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function collections(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

            if ($this->collectionSchema->available()) {
                $this->collections->publicSitemapQuery()
                    ->cursor()
                    ->each(function (Model $collection): void {
                        if (! $collection instanceof CatalogCollection) {
                            return;
                        }

                        $cover = $collection->cover_path !== null && $collection->cover_version > 0
                            ? route('collections.cover', [
                                'publicId' => $collection->public_id,
                                'version' => $collection->cover_version,
                            ])
                            : null;
                        $this->writeSitemapUrlWithImage(
                            route('collections.show', ['collectionSlug' => $collection->slug]),
                            $collection->updated_at,
                            'weekly',
                            $collection->is_featured ? '0.8' : '0.6',
                            $cover,
                            $cover === null ? null : $collection->name,
                        );
                    });
            }

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function videos(int $page): StreamedResponse
    {
        $page = max(1, $page);

        return response()->stream(function () use ($page): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">'."\n";

            $this->videoSitemapMediaQuery()
                ->with([
                    'catalogTitle:id,slug,title,description,poster_url,updated_at,indexed_at',
                    'season:id,number',
                    'episode:id,number,title',
                ])
                ->orderBy('id')
                ->forPage($page, self::VIDEO_SITEMAP_PAGE_SIZE)
                ->get()
                ->each(function (LicensedMedia $media): void {
                    $title = $media->catalogTitle;

                    if ($title === null) {
                        return;
                    }

                    $query = ['catalogTitle' => $title, 'media' => $media->id];

                    if ($media->episode_id) {
                        $query['episode'] = $media->episode_id;
                    }

                    $this->writeVideoSitemapUrl(
                        route('titles.show', $query),
                        $title->indexed_at ?: $title->updated_at,
                        $title->poster_url,
                        $media->title ?: $title->title,
                        $this->seoDescription($title->description ?: 'Сериал '.$title->title.' смотреть онлайн во встроенном плеере.'),
                    );
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function feed(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n";
            echo "    <channel>\n";
            echo '        <title>'.$this->xml($this->siteName())."</title>\n";
            echo '        <link>'.$this->xml(route('home'))."</link>\n";
            echo '        <description>'.$this->xml('Новые и обновленные сериалы каталога')."</description>\n";
            echo '        <language>ru</language>'."\n";
            echo '        <atom:link href="'.$this->xml(route('feed')).'" rel="self" type="application/rss+xml" />'."\n";

            $this->titles->visibleTo(null)
                ->select(['id', 'slug', 'title', 'description', 'poster_url', 'updated_at', 'indexed_at'])
                ->whereNotNull('slug')
                ->latest('indexed_at')
                ->cursor()
                ->each(function (CatalogTitle $title): void {
                    $url = route('titles.show', $title);
                    echo "        <item>\n";
                    echo '            <title>'.$this->xml($title->title)."</title>\n";
                    echo '            <link>'.$this->xml($url)."</link>\n";
                    echo '            <guid isPermaLink="true">'.$this->xml($url)."</guid>\n";
                    echo '            <pubDate>'.$this->xml(Carbon::parse($title->indexed_at ?: $title->updated_at ?: now())->toRssString())."</pubDate>\n";
                    echo '            <description>'.$this->xml($this->plainText($title->description ?: 'Сериал '.$title->title.' смотреть онлайн.'))."</description>\n";
                    echo "        </item>\n";
                });

            echo "    </channel>\n";
            echo '</rss>'."\n";
        }, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    public function openSearch(): StreamedResponse
    {
        return response()->stream(function (): void {
            $shortName = Str::limit($this->siteName(), 16, '');

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">'."\n";
            echo '    <ShortName>'.$this->xml($shortName)."</ShortName>\n";
            echo '    <Description>'.$this->xml('Поиск сериалов по каталогу')."</Description>\n";
            echo '    <InputEncoding>UTF-8</InputEncoding>'."\n";
            echo '    <Url type="text/html" method="get" template="'.$this->xml(route('titles.index').'?q={searchTerms}').'" />'."\n";
            echo '</OpenSearchDescription>'."\n";
        }, 200, ['Content-Type' => 'application/opensearchdescription+xml; charset=UTF-8']);
    }

    public function llms(): StreamedResponse
    {
        return response()->stream(function (): void {
            $titleCount = $this->titles->visibleTo(null)->count();
            $episodeCount = Episode::query()
                ->availableTo(null)
                ->whereHas('season', fn (Builder $query): Builder => $query->whereIn(
                    'seasons.id',
                    Season::query()->availableTo(null)->select('seasons.id'),
                ))
                ->whereHas('season.catalogTitle', fn (Builder $query): Builder => $query->whereIn(
                    'catalog_titles.id',
                    $this->titles->visibleTo(null)->select('catalog_titles.id'),
                ))
                ->count();
            $mediaCount = LicensedMedia::query()
                ->availableTo(null)
                ->forAvailableReleases(null)
                ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'))
                ->count();

            echo '# '.$this->siteName()."\n\n";
            echo "Каталог сериалов онлайн на русском языке. Данные автоматически обновляются и включают названия, оригинальные названия, алиасы, описания, постеры, жанры, страны, актеров, режиссеров, рейтинги, сезоны, серии и удаленные видео-файлы.\n\n";
            echo "## Статистика\n\n";
            echo '- Сериалов: '.$titleCount."\n";
            echo '- Серий: '.$episodeCount."\n";
            echo '- Видео-файлов: '.$mediaCount."\n\n";
            echo "## Основные URL\n\n";
            echo '- Главная: '.route('home')."\n";
            echo '- Каталог: '.route('titles.index')."\n";
            echo '- Подборки: '.route('collections.index')."\n";
            echo '- Сериалы текущего года: '.route('titles.year', ['year' => now()->year])."\n";
            echo '- Карта посадочных страниц: '.route('sitemap.landings')."\n";
            echo '- Индекс карты сайта: '.route('sitemap.index')."\n";
            echo '- Лента обновлений: '.route('feed')."\n";
            echo '- Описание поиска: '.route('opensearch')."\n\n";
            echo "## Поиск\n\n";
            echo 'Используйте '.route('titles.index')."?q={query} для поиска только по основным, оригинальным и альтернативным названиям. Актеры, режиссеры, жанры и страны доступны через отдельные справочники и фильтры.\n";
        }, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function writeSitemapIndexUrl(string $loc, mixed $lastmod): void
    {
        echo "    <sitemap>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo "    </sitemap>\n";
    }

    private function writeSitemapUrl(string $loc, mixed $lastmod, string $changefreq, string $priority): void
    {
        echo "    <url>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo '        <changefreq>'.$this->xml($changefreq)."</changefreq>\n";
        echo '        <priority>'.$this->xml($priority)."</priority>\n";
        echo "    </url>\n";
    }

    private function writeSitemapUrlWithImage(
        string $loc,
        mixed $lastmod,
        string $changefreq,
        string $priority,
        ?string $imageUrl,
        ?string $imageTitle,
    ): void {
        echo "    <url>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo '        <changefreq>'.$this->xml($changefreq)."</changefreq>\n";
        echo '        <priority>'.$this->xml($priority)."</priority>\n";

        if ($imageUrl !== null && trim($imageUrl) !== '') {
            echo "        <image:image>\n";
            echo '            <image:loc>'.$this->xml($imageUrl)."</image:loc>\n";

            if ($imageTitle !== null && trim($imageTitle) !== '') {
                echo '            <image:title>'.$this->xml($imageTitle)."</image:title>\n";
            }

            echo "        </image:image>\n";
        }

        echo "    </url>\n";
    }

    private function writeVideoSitemapUrl(
        string $loc,
        mixed $lastmod,
        ?string $thumbnailUrl,
        string $title,
        string $description,
    ): void {
        echo "    <url>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo "        <video:video>\n";

        if ($thumbnailUrl !== null && trim($thumbnailUrl) !== '') {
            echo '            <video:thumbnail_loc>'.$this->xml($thumbnailUrl)."</video:thumbnail_loc>\n";
        }

        echo '            <video:title>'.$this->xml(Str::limit($title, 100, ''))."</video:title>\n";
        echo '            <video:description>'.$this->xml(Str::limit($description, 2000, ''))."</video:description>\n";
        echo '            <video:player_loc>'.$this->xml($loc.'#player')."</video:player_loc>\n";
        echo '            <video:publication_date>'.$this->xml($this->sitemapDate($lastmod))."</video:publication_date>\n";
        echo "            <video:family_friendly>yes</video:family_friendly>\n";
        echo "            <video:live>no</video:live>\n";
        echo "        </video:video>\n";
        echo "    </url>\n";
    }

    /**
     * @return Builder<LicensedMedia>
     */
    private function videoSitemapMediaQuery(): Builder
    {
        return LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereIn('catalog_title_id', $this->titles->visibleTo(null)->select('id'));
    }

    /**
     * @return list<string>
     */
    private function landingFilterTypes(): array
    {
        return ['genre', 'country', 'actor', 'director', 'translation', 'age_rating'];
    }

    /**
     * @return Collection<int, int>
     */
    private function landingYears(): Collection
    {
        return $this->titles->visibleTo(null)
            ->select('year')
            ->whereNotNull('year')
            ->whereBetween('year', [$this->minimumYear(), $this->maximumYear()])
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(12)
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->values();
    }

    private function minimumYear(): int
    {
        return max(1900, (int) config('catalog.directories.minimum_year', 1900));
    }

    private function maximumYear(): int
    {
        $configured = config('catalog.directories.maximum_year');

        return is_numeric($configured)
            ? max($this->minimumYear(), (int) $configured)
            : now()->year + 1;
    }

    private function siteName(): string
    {
        return (string) config('app.name', 'Каталог сериалов');
    }

    private function seoDescription(?string $value, int $limit = 180): string
    {
        return Str::limit($this->plainText($value), $limit, '...');
    }

    private function plainText(?string $value): string
    {
        $text = strip_tags((string) $value);

        return preg_replace('/\s+/u', ' ', trim($text)) ?: '';
    }

    private function sitemapDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toAtomString();
            } catch (Throwable) {
                return now()->toAtomString();
            }
        }

        return now()->toAtomString();
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
