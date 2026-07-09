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
     *     seasons: list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>,
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

    private function cleanSeasonTitle(string $title): string
    {
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = (string) Str::of($title)->replace("\xc2\xa0", ' ')->squish();
        $title = preg_replace('/^\s*>+\s*/u', '', $title) ?: $title;
        $title = preg_replace('/\s*\(\d{2}\.\d{2}\.\d{4}.*$/u', '', $title) ?: $title;
        $title = preg_replace('/\s+онлайн\s*$/iu', '', $title) ?: $title;

        return trim($title);
    }

    /**
     * @return array{latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}
     */
    private function emptySeasonReleaseStatus(): array
    {
        return [
            'latest_episode_released_at' => null,
            'episodes_released' => null,
            'episodes_total' => null,
            'translation_name' => null,
            'release_status_text' => null,
        ];
    }

    /**
     * @return array{latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}
     */
    private function seasonReleaseStatus(string $text): array
    {
        $status = $this->emptySeasonReleaseStatus();
        $normalized = (string) Str::of(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replace("\xc2\xa0", ' ')
            ->squish();

        if ($normalized === '') {
            return $status;
        }

        if (preg_match('/\(\s*(\d{2}\.\d{2}\.\d{4}.*?)\s*\)\s*$/u', $normalized, $matches) === 1) {
            $status['release_status_text'] = $this->stringValue($matches[1]);
        }

        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/u', $normalized, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (checkdate($month, $day, $year)) {
                $status['latest_episode_released_at'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        if (preg_match('/(\d+)\s*сер(?:ия|ии|ий)/iu', $normalized, $matches) === 1) {
            $status['episodes_released'] = (int) $matches[1];
        }

        if (preg_match('/из\s*(\d+)/iu', $normalized, $matches) === 1) {
            $status['episodes_total'] = (int) $matches[1];
        }

        $status['translation_name'] = Arr::first($this->translationNamesFromText($normalized));

        return $status;
    }

    private function extractYear(?string $text): ?int
    {
        if ($text === null || preg_match('/\b(19|20)\d{2}\b/', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    /**
     * @return list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>
     */
    private function seasons(DOMXPath $xpath, string $baseUrl): array
    {
        $seasons = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $rawText = $this->stringValue($node->textContent) ?? '';
            $text = $this->cleanSeasonTitle($rawText);
            $releaseStatus = $this->seasonReleaseStatus($rawText);
            $number = ($href !== null ? $this->seasonNumberFromUrl($href) : null) ?? $this->seasonNumber($text);

            if ($number === null && $seasons === []) {
                $number = 1;
            }

            if ($number === null || $number <= 0) {
                continue;
            }

            $sourceUrl = $href ? $this->normalizeRelative($href, $baseUrl) : null;
            $seasons[$number] = [
                'number' => $number,
                'title' => $text !== '' ? $text : "Сезон {$number}",
                'source_url' => $sourceUrl,
                'latest_episode_released_at' => $releaseStatus['latest_episode_released_at'],
                'episodes_released' => $releaseStatus['episodes_released'],
                'episodes_total' => $releaseStatus['episodes_total'],
                'translation_name' => $releaseStatus['translation_name'],
                'release_status_text' => $releaseStatus['release_status_text'],
            ];
        }

        if ($seasons === []) {
            $releaseStatus = $this->emptySeasonReleaseStatus();

            $seasons[1] = [
                'number' => 1,
                'title' => 'Сезон 1',
                'source_url' => $baseUrl,
                'latest_episode_released_at' => $releaseStatus['latest_episode_released_at'],
                'episodes_released' => $releaseStatus['episodes_released'],
                'episodes_total' => $releaseStatus['episodes_total'],
                'translation_name' => $releaseStatus['translation_name'],
                'release_status_text' => $releaseStatus['release_status_text'],
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
                $episodeTitle = $this->firstNonEmpty([
                    $episode['title'] ?? null,
                    $episode['name'] ?? null,
                    $episode['t'] ?? null,
                ]);
                $episodes[$number] = [
                    'season_number' => $seasonNumber,
                    'number' => $number,
                    'title' => $episodeTitle ?? $number.' серия',
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

        foreach ($this->valueList($this->infoField($xpath, 'Ограничение')) as $name) {
            $key = 'age_rating|'.Str::lower($name);
            $items[$key] = ['type' => 'age_rating', 'name' => $name, 'source_url' => null];
        }

        foreach (['Перевод', 'Озвучка'] as $label) {
            foreach ($this->valueList($this->infoField($xpath, $label)) as $name) {
                $key = 'translation|'.Str::lower($name);
                $items[$key] = ['type' => 'translation', 'name' => $name, 'source_url' => null];
            }
        }

        foreach ($this->valueList($this->infoField($xpath, 'Статус')) as $name) {
            $key = 'status|'.Str::lower($name);
            $items[$key] = ['type' => 'status', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->infoField($xpath, 'Канал')) as $name) {
            $key = 'network|'.Str::lower($name);
            $items[$key] = ['type' => 'network', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->infoField($xpath, 'Студия')) as $name) {
            $key = 'studio|'.Str::lower($name);
            $items[$key] = ['type' => 'studio', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->seasonListTranslations($xpath) as $name) {
            $key = 'translation|'.Str::lower($name);
            $items[$key] = ['type' => 'translation', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->firstText($xpath, ['//*[@itemprop="directors"]//*[@itemprop="name"]'])) as $name) {
            $key = 'director|'.Str::lower($name);
            $items[$key] = ['type' => 'director', 'name' => $name, 'source_url' => null];
        }

        foreach ([
            '//*[@data-info="actor"]//*[@itemprop="name"]',
            '//*[@itemprop="actor"]//*[@itemprop="name"]',
            '//*[@itemprop="actors"]//*[@itemprop="name"]',
        ] as $actorQuery) {
            foreach ($xpath->query($actorQuery) ?: [] as $node) {
                $name = $this->stringValue($node->textContent);

                if ($name === null) {
                    continue;
                }

                $key = 'actor|'.Str::lower($name);
                $items[$key] = ['type' => 'actor', 'name' => $name, 'source_url' => null];
            }
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

        foreach ($this->tagListTaxonomies($xpath, $baseUrl) as $item) {
            $key = 'tag|'.Str::lower($item['name']);
            $items[$key] = $item;
        }

        if ($this->hasSubtitles($xpath)) {
            $items['tag|субтитры'] = ['type' => 'tag', 'name' => 'субтитры', 'source_url' => null];
        }

        return array_values($items);
    }

    /**
     * @return list<string>
     */
    private function seasonListTranslations(DOMXPath $xpath): array
    {
        $translations = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]//a') ?: [] as $node) {
            $text = $this->stringValue($node->textContent);

            if ($text === null) {
                continue;
            }

            foreach ($this->translationNamesFromText($text) as $name) {
                $translations[Str::lower($name)] = $name;
            }
        }

        return array_values($translations);
    }

    /**
     * @return list<string>
     */
    private function translationNamesFromText(string $text): array
    {
        if (preg_match_all('/\(([^()]{2,80})\)/u', $text, $matches) !== 1) {
            return [];
        }

        $translations = [];

        foreach ($matches[1] as $match) {
            $name = $this->stringValue($match);

            if ($name === null || preg_match('/^\d{2}\.\d{2}\.\d{4}/u', $name) === 1) {
                continue;
            }

            if (preg_match('/(?:сер(?:ия|ии|ий)|из|\?\?)/iu', $name) === 1) {
                continue;
            }

            $translations[Str::lower($name)] = $name;
        }

        return array_values($translations);
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function tagListTaxonomies(DOMXPath $xpath, string $baseUrl): array
    {
        $items = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " b-taglist ")]//a[@href]') ?: [] as $node) {
            $name = $this->stringValue($node->textContent);
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;

            if ($name === null || $href === null || mb_strlen($name) > 80) {
                continue;
            }

            $items[Str::lower($name)] = [
                'type' => 'tag',
                'name' => $name,
                'source_url' => $this->normalizeRelative($href, $baseUrl),
            ];
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

            $pattern = '/'.preg_quote($label, '/').'\s*:\s*(.*?)(?=\s*(?:Оригинал|Жанр|Ограничение|Страна|Вышел|Режиссер|Перевод|Озвучка|Статус|Канал|Студия|IMDB|КиноПоиск)\s*:|$)/u';

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
        if (is_string($value) && preg_match('/[,;|]/u', $value) === 1) {
            $value = preg_split('/\s*[,;|]\s*/u', $value) ?: [$value];
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
