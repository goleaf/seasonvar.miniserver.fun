<?php

namespace App\Services\Seasonvar;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeasonvarCatalogParser
{
    public function __construct(private readonly SeasonvarUrl $seasonvarUrl) {}

    /**
     * @return array{
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
     * }
     */
    public function parse(string $html, string $url): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $structuredData = $this->structuredData($xpath);

        $title = $this->cleanTitle($this->firstNonEmpty([
            Arr::get($structuredData, 'name'),
            Arr::get($structuredData, 'headline'),
            $this->firstText($xpath, [
                '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo-title ")]',
                '//*[@itemprop="name" and contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo-title ")]',
                '//meta[@property="og:title"]/@content',
                '//meta[@name="twitter:title"]/@content',
                '//h1',
                '//title',
            ]),
        ]) ?? 'Без названия');

        $originalTitle = $this->firstNonEmpty([
            Arr::get($structuredData, 'alternateName'),
            $this->firstText($xpath, [
                '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")][contains(., "Оригинал:")]/span[1]',
                '//*[contains(@class, "original") or contains(@class, "altname")]',
            ]),
        ]);

        if ($originalTitle === $title) {
            $originalTitle = null;
        }

        if ($originalTitle !== null && $this->containsCyrillic($originalTitle)) {
            $originalTitle = null;
        }

        $description = $this->firstNonEmpty([
            Arr::get($structuredData, 'description'),
            $this->firstText($xpath, [
                '//p[@itemprop="description"]',
                '//meta[@name="description"]/@content',
                '//meta[@property="og:description"]/@content',
                '//meta[@name="twitter:description"]/@content',
            ]),
        ]);

        $posterUrl = $this->firstNonEmpty([
            Arr::get($structuredData, 'image'),
            $this->firstText($xpath, [
                '//img[@itemprop="thumbnailUrl"]/@src',
                '//*[contains(concat(" ", normalize-space(@class), " "), " poster ")]//img/@src',
                '//*[@itemprop="thumbnail"]//*[@itemprop="contentUrl"]/@href',
                '//meta[@property="og:image"]/@content',
                '//meta[@name="twitter:image"]/@content',
                '//img[contains(@class, "poster")]/@src',
                '//img[contains(@class, "cover")]/@src',
                '//img[contains(@class, "img") and contains(@src, "poster")]/@src',
            ]),
        ]);

        $year = $this->extractYear($this->firstNonEmpty([
            $this->infoField($xpath, 'Вышел'),
            Arr::get($structuredData, 'datePublished'),
            Arr::get($structuredData, 'releasedEvent.startDate'),
        ]).' '.$title.' '.$description);

        $seasons = $this->seasons($xpath, $url);
        $currentSeasonNumber = $this->seasonNumberFromUrl($url) ?? $this->seasonNumber($title) ?? 1;

        return [
            'title' => $title,
            'original_title' => $originalTitle,
            'type' => 'serial',
            'year' => $year,
            'description' => $description,
            'poster_url' => $posterUrl ? $this->normalizeRelative($posterUrl, $url) : null,
            'external_id' => $this->seasonvarUrl->externalSerialId($url),
            'current_season_number' => $currentSeasonNumber,
            'seasons' => $seasons,
            'episodes' => $this->episodes($html, $url, $currentSeasonNumber),
            'taxonomies' => $this->taxonomies($xpath, $url, $structuredData),
        ];
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstText(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = trim($nodes->item(0)?->textContent ?? '');

            if ($value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $value = (string) Str::of($value)->replace("\xc2\xa0", ' ')->squish();

            return $value !== '' ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['@value', 'name', 'headline', 'url', 'contentUrl'] as $key) {
            if (array_key_exists($key, $value)) {
                $string = $this->stringValue($value[$key]);

                if ($string !== null) {
                    return $string;
                }
            }
        }

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', $title) ?: $title;
        $title = preg_replace('/\s*(?:[-|]\s*)?(смотреть|seasonvar).*$/iu', '', $title) ?: $title;
        $title = preg_replace('/^\s*сериал\s+/iu', '', $title) ?: $title;
        $title = preg_replace('/\s+\d+\s*(?:сезон|season)\s*(?:онлайн)?\s*$/iu', '', $title) ?: $title;
        $title = preg_replace('/\s+онлайн\s*$/iu', '', $title) ?: $title;

        return trim($title);
    }

    private function extractYear(?string $text): ?int
    {
        if ($text === null || preg_match('/\b(19|20)\d{2}\b/', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    /**
     * @return list<array{number: int, title: string|null, source_url: string|null}>
     */
    private function seasons(DOMXPath $xpath, string $baseUrl): array
    {
        $seasons = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $text = trim($node->textContent);
            $number = ($href !== null ? $this->seasonNumberFromUrl($href) : null) ?? $this->seasonNumber($text);

            if ($number === null || $number <= 0) {
                continue;
            }

            $sourceUrl = $href ? $this->normalizeRelative($href, $baseUrl) : null;
            $seasons[$number] = [
                'number' => $number,
                'title' => $text !== '' ? $text : "Сезон {$number}",
                'source_url' => $sourceUrl,
            ];
        }

        if ($seasons === []) {
            $seasons[1] = [
                'number' => 1,
                'title' => 'Сезон 1',
                'source_url' => $baseUrl,
            ];
        }

        ksort($seasons);

        return array_values($seasons);
    }

    private function seasonNumber(string $value): ?int
    {
        foreach ([
            '/(?:season|sezon|сезон)[^\d]{0,12}(\d+)/iu',
            '/(\d+)[^\d]{0,12}(?:season|sezon|сезон)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function seasonNumberFromUrl(string $value): ?int
    {
        foreach ([
            '/-(\d+)-season/i',
            '/_s(\d+)\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @return list<array{season_number: int, number: int, title: string|null, source_url: string|null}>
     */
    private function episodes(string $html, string $baseUrl, int $seasonNumber): array
    {
        if (preg_match('/var\s+arEpisodes\s*=\s*(\[.*?\]);/s', $html, $matches) !== 1) {
            return [];
        }

        $decoded = json_decode($matches[1], true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [];
        }

        $episodes = [];

        foreach ($decoded as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $slug => $episode) {
                if (! is_array($episode) || ! isset($episode['n']) || ! is_numeric($episode['n'])) {
                    continue;
                }

                $number = (int) $episode['n'];

                if ($number <= 0) {
                    continue;
                }

                $episodeSlug = is_string($slug) && $slug !== '' ? $slug : $number.'_seriya';
                $episodes[$number] = [
                    'season_number' => $seasonNumber,
                    'number' => $number,
                    'title' => $number.' серия',
                    'source_url' => $baseUrl.'#'.$episodeSlug,
                ];
            }
        }

        ksort($episodes);

        return array_values($episodes);
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function taxonomies(DOMXPath $xpath, string $baseUrl, array $structuredData): array
    {
        $items = [];

        foreach ($this->structuredTaxonomies($structuredData) as $item) {
            $key = $item['type'].'|'.Str::lower($item['name']);
            $items[$key] = $item;
        }

        foreach ($this->valueList($this->firstText($xpath, ['//*[@itemprop="genre"]'])) as $name) {
            $key = 'genre|'.Str::lower($name);
            $items[$key] = ['type' => 'genre', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->infoField($xpath, 'Жанр')) as $name) {
            $key = 'genre|'.Str::lower($name);
            $items[$key] = ['type' => 'genre', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->infoField($xpath, 'Страна')) as $name) {
            $key = 'country|'.Str::lower($name);
            $items[$key] = ['type' => 'country', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->firstText($xpath, ['//*[@itemprop="directors"]//*[@itemprop="name"]'])) as $name) {
            $key = 'director|'.Str::lower($name);
            $items[$key] = ['type' => 'director', 'name' => $name, 'source_url' => null];
        }

        foreach ($xpath->query('//*[@data-info="actor"]//*[@itemprop="name"]') ?: [] as $node) {
            $name = $this->stringValue($node->textContent);

            if ($name === null) {
                continue;
            }

            $key = 'actor|'.Str::lower($name);
            $items[$key] = ['type' => 'actor', 'name' => $name, 'source_url' => null];
        }

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $name = trim($node->textContent);

            if ($href === null || $name === '') {
                continue;
            }

            $normalizedHref = Str::lower($href);
            $type = match (true) {
                Str::contains($normalizedHref, ['genre', 'janr', 'zhanr']) => 'genre',
                Str::contains($normalizedHref, ['country', 'strana']) => 'country',
                Str::contains($normalizedHref, ['actor', 'akter']) => 'actor',
                Str::contains($normalizedHref, ['director', 'rezhisser']) => 'director',
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $key = $type.'|'.Str::lower($name);
            $items[$key] = [
                'type' => $type,
                'name' => $name,
                'source_url' => $this->normalizeRelative($href, $baseUrl),
            ];
        }

        if ($this->hasSubtitles($xpath)) {
            $items['tag|субтитры'] = ['type' => 'tag', 'name' => 'субтитры', 'source_url' => null];
        }

        return array_values($items);
    }

    private function hasSubtitles(DOMXPath $xpath): bool
    {
        foreach ($xpath->query('//body//*[not(self::script) and not(self::style)]') ?: [] as $node) {
            $text = $this->stringValue($node->textContent);

            if ($text !== null && preg_match('/(?:субтитр|subtitles?|subs?)/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function infoField(DOMXPath $xpath, string $label): ?string
    {
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]') ?: [] as $node) {
            $text = $this->stringValue($node->textContent);

            if ($text === null || ! Str::contains($text, $label.':')) {
                continue;
            }

            $pattern = '/'.preg_quote($label, '/').'\s*:\s*(.*?)(?=\s*(?:Оригинал|Жанр|Ограничение|Страна|Вышел|Режиссер|IMDB|КиноПоиск)\s*:|$)/u';

            if (preg_match($pattern, $text, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function structuredTaxonomies(array $structuredData): array
    {
        $items = [];

        foreach ($this->valueList(Arr::get($structuredData, 'genre')) as $name) {
            $items[] = ['type' => 'genre', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList(Arr::get($structuredData, 'actor')) as $name) {
            $items[] = ['type' => 'actor', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList(Arr::get($structuredData, 'director')) as $name) {
            $items[] = ['type' => 'director', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList(Arr::get($structuredData, 'countryOfOrigin')) as $name) {
            $items[] = ['type' => 'country', 'name' => $name, 'source_url' => null];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function valueList(mixed $value): array
    {
        if (is_string($value) && Str::contains($value, ',')) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            $string = $this->stringValue($value);

            return $string === null ? [] : [$string];
        }

        $items = [];

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                $items[Str::lower($string)] = $string;
            }
        }

        return array_values($items);
    }

    private function structuredData(DOMXPath $xpath): array
    {
        $fallback = [];

        foreach ($xpath->query('//script[contains(@type, "ld+json")]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            foreach ($this->structuredItems($decoded) as $item) {
                $fallback = $fallback === [] ? $item : $fallback;
                $types = array_map(
                    fn (mixed $type): string => Str::lower((string) $type),
                    Arr::wrap(Arr::get($item, '@type')),
                );

                if (array_intersect($types, ['tvseries', 'movie', 'creativework', 'videoobject']) !== []) {
                    return $item;
                }
            }
        }

        return $fallback;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredItems(array $value): array
    {
        if (array_key_exists('@graph', $value) && is_array($value['@graph'])) {
            return $this->structuredItems($value['@graph']);
        }

        if (! array_is_list($value)) {
            return [$value];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                array_push($items, ...$this->structuredItems($item));
            }
        }

        return $items;
    }

    private function normalizeRelative(string $url, string $baseUrl): string
    {
        try {
            return $this->seasonvarUrl->normalize($url, $baseUrl);
        } catch (InvalidArgumentException) {
            return $url;
        }
    }

    private function containsCyrillic(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1;
    }
}
