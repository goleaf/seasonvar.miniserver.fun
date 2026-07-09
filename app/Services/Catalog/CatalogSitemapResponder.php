<?php

namespace App\Services\Catalog;

use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Director;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
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
    private const FILTER_RELATIONS = [
        'genre' => ['model' => Genre::class, 'relation' => 'genres'],
        'country' => ['model' => Country::class, 'relation' => 'countries'],
        'actor' => ['model' => Actor::class, 'relation' => 'actors'],
        'director' => ['model' => Director::class, 'relation' => 'directors'],
        'age_rating' => ['model' => AgeRating::class, 'relation' => 'ageRatings'],
        'translation' => ['model' => Translation::class, 'relation' => 'translations'],
        'status' => ['model' => CatalogStatus::class, 'relation' => 'statuses'],
        'network' => ['model' => Network::class, 'relation' => 'networks'],
        'studio' => ['model' => Studio::class, 'relation' => 'studios'],
        'tag' => ['model' => Tag::class, 'relation' => 'tags'],
    ];

    private const SITEMAP_PAGE_SIZE = 10000;

    private const VIDEO_SITEMAP_PAGE_SIZE = 5000;

    public function index(): StreamedResponse
    {
        return response()->stream(function (): void {
            $titleSitemapPages = max(1, (int) ceil(CatalogTitle::query()
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->count() / self::SITEMAP_PAGE_SIZE));
            $videoSitemapPages = max(1, (int) ceil($this->videoSitemapMediaQuery()->count() / self::VIDEO_SITEMAP_PAGE_SIZE));

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $this->writeSitemapIndexUrl(route('sitemap.static'), now());
            $this->writeSitemapIndexUrl(route('sitemap.taxonomies'), now());
            $this->writeSitemapIndexUrl(route('sitemap.landings'), now());

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

            CatalogTitle::query()
                ->select('year')
                ->where('is_published', true)
                ->whereNotNull('year')
                ->where('year', '>=', 1900)
                ->where('year', '<=', (int) now()->format('Y') + 1)
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

            foreach (self::FILTER_RELATIONS as $filterType => $config) {
                $modelClass = $config['model'];

                $modelClass::query()
                    ->select(['id', 'slug'])
                    ->whereHas('catalogTitles', fn (Builder $query): Builder => $query->where('is_published', true))
                    ->orderBy('id')
                    ->chunkById(1000, function (Collection $taxonomies) use ($filterType): void {
                        foreach ($taxonomies as $taxonomy) {
                            $this->writeSitemapUrl(route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]), now(), 'weekly', '0.7');
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
                $modelClass = self::FILTER_RELATIONS[$filterType]['model'];

                $taxonomies = $modelClass::query()
                    ->select(['id', 'slug'])
                    ->withCount(['catalogTitles as catalog_titles_count' => fn (Builder $query): Builder => $query->where('is_published', true)])
                    ->whereHas('catalogTitles', fn (Builder $query): Builder => $query->where('is_published', true))
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

                        $url = route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]).'?year='.$year;
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

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $catalogTitleRelation = (new CatalogTitle)->{self::FILTER_RELATIONS[$filterType]['relation']}();
        $pivotTable = $catalogTitleRelation->getTable();
        $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
        $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();

        return DB::table($pivotTable)
            ->join($catalogTitleTable, $catalogTitleTable.'.id', '=', $pivotTable.'.'.$titlePivotKey)
            ->where($catalogTitleTable.'.is_published', true)
            ->whereIn($pivotTable.'.'.$relatedPivotKey, $taxonomyIds)
            ->whereIn($catalogTitleTable.'.year', $years)
            ->select([
                $pivotTable.'.'.$relatedPivotKey.' as taxonomy_id',
                $catalogTitleTable.'.year as year',
            ])
            ->groupBy($pivotTable.'.'.$relatedPivotKey, $catalogTitleTable.'.year')
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

            CatalogTitle::query()
                ->select(['id', 'slug', 'title', 'poster_url', 'updated_at', 'indexed_at'])
                ->where('is_published', true)
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
                    $contentUrl = $media->playback_url ?: $media->path;

                    if ($title === null || ! $this->isAbsoluteUrl($contentUrl)) {
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
                        $contentUrl,
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

            CatalogTitle::query()
                ->select(['id', 'slug', 'title', 'description', 'poster_url', 'updated_at', 'indexed_at'])
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->latest('indexed_at')
                ->limit(100)
                ->get()
                ->each(function (CatalogTitle $title): void {
                    $url = route('titles.show', $title);
                    echo "        <item>\n";
                    echo '            <title>'.$this->xml($title->title)."</title>\n";
                    echo '            <link>'.$this->xml($url)."</link>\n";
                    echo '            <guid isPermaLink="true">'.$this->xml($url)."</guid>\n";
                    echo '            <pubDate>'.$this->xml(Carbon::parse($title->indexed_at ?: $title->updated_at ?: now())->toRssString())."</pubDate>\n";
                    echo '            <description>'.$this->xml($this->seoDescription($title->description ?: 'Сериал '.$title->title.' смотреть онлайн.'))."</description>\n";
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
            $titleCount = CatalogTitle::query()->where('is_published', true)->count();
            $episodeCount = Episode::query()->count();
            $mediaCount = LicensedMedia::query()->published()->count();

            echo '# '.$this->siteName()."\n\n";
            echo "Каталог сериалов онлайн на русском языке. Данные автоматически импортируются из Seasonvar и включают названия, оригинальные названия, алиасы, описания, постеры, жанры, страны, актеров, режиссеров, рейтинги, сезоны, серии и удаленные видео-файлы.\n\n";
            echo "## Статистика\n\n";
            echo '- Сериалов: '.$titleCount."\n";
            echo '- Серий: '.$episodeCount."\n";
            echo '- Видео-файлов: '.$mediaCount."\n\n";
            echo "## Основные URL\n\n";
            echo '- Главная: '.route('home')."\n";
            echo '- Каталог: '.route('titles.index')."\n";
            echo '- Сериалы текущего года: '.route('titles.year', ['year' => now()->year])."\n";
            echo '- Карта посадочных страниц: '.route('sitemap.landings')."\n";
            echo '- Индекс карты сайта: '.route('sitemap.index')."\n";
            echo '- Лента обновлений: '.route('feed')."\n";
            echo '- Описание поиска: '.route('opensearch')."\n\n";
            echo "## Поиск\n\n";
            echo 'Используйте '.route('titles.index')."?q={query} для поиска по названиям, описаниям, актерам, режиссерам, жанрам, странам и другим связям каталога.\n";
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
        string $contentUrl,
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
        echo '            <video:content_loc>'.$this->xml($contentUrl)."</video:content_loc>\n";
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
            ->where(function (Builder $query): void {
                $query->where('playback_url', 'like', 'https://%')
                    ->orWhere('playback_url', 'like', 'http://%')
                    ->orWhere('path', 'like', 'https://%')
                    ->orWhere('path', 'like', 'http://%');
            });
    }

    private function isAbsoluteUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        return Str::startsWith($url, ['https://', 'http://']);
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
        return CatalogTitle::query()
            ->select('year')
            ->where('is_published', true)
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(12)
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->values();
    }

    private function siteName(): string
    {
        return (string) config('app.name', 'Каталог сериалов');
    }

    private function seoDescription(?string $value, int $limit = 180): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';

        return Str::limit($text, $limit, '...');
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
