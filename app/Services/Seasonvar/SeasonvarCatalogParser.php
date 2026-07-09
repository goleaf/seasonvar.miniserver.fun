<?php

namespace App\Services\Seasonvar;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeasonvarCatalogParser
{
    /**
     * @var list<string>
     */
    private const MEDIA_EXTENSIONS = ['m3u8', 'm3u', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi'];

    /**
     * @var list<string>
     */
    private const INFO_LABELS = [
        'Альтернативное название',
        'Ориентировочная дата выхода',
        'Продолжительность',
        'Ограничение',
        'КиноПоиск',
        'Кинопоиск',
        'Режиссёр',
        'Режиссер',
        'Оригинал',
        'Актеры',
        'Актёры',
        'В ролях',
        'Перевод',
        'Озвучка',
        'Страна',
        'Вышел',
        'Статус',
        'Канал',
        'Студия',
        'Жанр',
        'IMDb',
        'IMDB',
        'Качество',
        'Сценарист',
        'Продюсер',
        'Композитор',
        'Оператор',
        'Премьера',
        'Слоган',
    ];

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
     *     media: list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>,
     *     taxonomies: list<array{type: string, name: string, source_url: string|null}>,
     *     ratings: list<array{provider: string, rating: float|null, votes: int|null, raw_value: string}>,
     *     aliases: list<array{name: string, type: string, source: string}>,
     *     reviews: list<array{author: string|null, body: string, published_at: string|null}>,
     *     parse_meta: array{info_labels: list<string>, has_info_list: bool, has_season_list: bool, has_episode_script: bool}
     * }
     */
    public function parse(string $html, string $url): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $structuredData = $this->structuredData($xpath);
        $infoFields = $this->infoFields($xpath);

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
            $this->firstInfoField($infoFields, ['Оригинал']),
            $this->firstText($xpath, ['//*[contains(@class, "original") or contains(@class, "altname")]']),
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
            $this->firstInfoField($infoFields, ['Вышел', 'Ориентировочная дата выхода']),
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
            'media' => $this->mediaCandidates($html, $xpath, $url, $currentSeasonNumber),
            'taxonomies' => $this->taxonomies($xpath, $url, $structuredData, $infoFields),
            'ratings' => $this->ratings($infoFields),
            'aliases' => $this->aliases($infoFields, $title, $originalTitle),
            'reviews' => $this->reviews($xpath),
            'parse_meta' => $this->parseMeta($xpath, $html, $infoFields),
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
     * @param  array<string, list<string>>  $infoFields
     * @return array{info_labels: list<string>, has_info_list: bool, has_season_list: bool, has_episode_script: bool}
     */
    private function parseMeta(DOMXPath $xpath, string $html, array $infoFields): array
    {
        return [
            'info_labels' => array_keys($infoFields),
            'has_info_list' => ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]')?->length ?? 0) > 0,
            'has_season_list' => ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]')?->length ?? 0) > 0,
            'has_episode_script' => Str::contains($html, 'arEpisodes'),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function infoFields(DOMXPath $xpath): array
    {
        $fields = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]') ?: [] as $node) {
            $text = $this->stringValue($node->textContent);

            if ($text === null) {
                continue;
            }

            foreach ($this->infoFieldsFromText($text) as $label => $values) {
                foreach ($values as $value) {
                    if ($value === '') {
                        continue;
                    }

                    $fields[$label] ??= [];
                    $fields[$label][Str::lower($value)] = $value;
                }
            }
        }

        return collect($fields)
            ->map(fn (array $values): array => array_values($values))
            ->all();
    }

    /**
     * @param  array<string, list<string>>  $infoFields
     * @param  list<string>  $labels
     */
    private function firstInfoField(array $infoFields, array $labels): ?string
    {
        foreach ($labels as $label) {
            foreach ($this->infoFieldValues($infoFields, $label) as $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $infoFields
     * @return list<string>
     */
    private function infoFieldValues(array $infoFields, string $label): array
    {
        $canonical = $this->canonicalInfoLabel($label);

        return $canonical !== null ? ($infoFields[$canonical] ?? []) : [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function infoFieldsFromText(string $text): array
    {
        $labels = collect(self::INFO_LABELS)
            ->sortByDesc(fn (string $label): int => Str::length($label))
            ->map(fn (string $label): string => preg_quote($label, '/'))
            ->implode('|');

        $matchesCount = preg_match_all('/('.$labels.')\s*:/iu', $text, $matches, PREG_OFFSET_CAPTURE);

        if ($matchesCount === false || $matchesCount === 0) {
            return [];
        }

        $fields = [];
        $count = count($matches[0]);

        for ($index = 0; $index < $count; $index++) {
            $rawLabel = $matches[1][$index][0];
            $label = $this->canonicalInfoLabel($rawLabel);

            if ($label === null) {
                continue;
            }

            $valueStart = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $valueEnd = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($text);
            $value = $this->stringValue(substr($text, $valueStart, max(0, $valueEnd - $valueStart)));

            if ($value === null) {
                continue;
            }

            $fields[$label] ??= [];
            $fields[$label][] = $value;
        }

        return $fields;
    }

    private function canonicalInfoLabel(string $label): ?string
    {
        $normalized = Str::lower(Str::squish($label));

        foreach (self::INFO_LABELS as $knownLabel) {
            if (Str::lower($knownLabel) === $normalized) {
                return match ($knownLabel) {
                    'Режиссёр' => 'Режиссер',
                    'Актёры' => 'Актеры',
                    'IMDb' => 'IMDB',
                    'Кинопоиск' => 'КиноПоиск',
                    default => $knownLabel,
                };
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $infoFields
     * @return list<array{provider: string, rating: float|null, votes: int|null, raw_value: string}>
     */
    private function ratings(array $infoFields): array
    {
        $ratings = [];

        foreach ([
            'imdb' => ['IMDB', 'IMDb'],
            'kinopoisk' => ['КиноПоиск', 'Кинопоиск'],
        ] as $provider => $labels) {
            $rawValue = $this->firstInfoField($infoFields, $labels);

            if ($rawValue === null) {
                continue;
            }

            $ratings[] = [
                'provider' => $provider,
                'rating' => $this->ratingValue($rawValue),
                'votes' => $this->ratingVotes($rawValue),
                'raw_value' => $rawValue,
            ];
        }

        return $ratings;
    }

    private function ratingValue(string $value): ?float
    {
        if (preg_match('/(\d{1,2}(?:[,.]\d{1,2})?)/u', $value, $matches) !== 1) {
            return null;
        }

        $rating = (float) str_replace(',', '.', $matches[1]);

        return $rating >= 0 && $rating <= 10 ? $rating : null;
    }

    private function ratingVotes(string $value): ?int
    {
        if (preg_match('/(?:\(|\s)(\d[\d\s]{1,12})\s*(?:голос|оцен|votes?|users?)?/iu', $value, $matches) !== 1) {
            return null;
        }

        $votes = (int) preg_replace('/\D/u', '', $matches[1]);

        return $votes > 0 ? $votes : null;
    }

    /**
     * @param  array<string, list<string>>  $infoFields
     * @return list<array{name: string, type: string, source: string}>
     */
    private function aliases(array $infoFields, string $title, ?string $originalTitle): array
    {
        $aliases = [];

        if ($originalTitle !== null) {
            $this->addAlias($aliases, $originalTitle, 'original', 'info');
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Альтернативное название'])) as $name) {
            $this->addAlias($aliases, $name, 'alternative', 'info');
        }

        foreach (explode('/', $title) as $name) {
            $this->addAlias($aliases, $name, 'source_title', 'title');
        }

        return array_values($aliases);
    }

    /**
     * @param  array<string, array{name: string, type: string, source: string}>  $aliases
     */
    private function addAlias(array &$aliases, ?string $name, string $type, string $source): void
    {
        $name = $this->stringValue($name);

        if ($name === null || Str::length($name) > 160) {
            return;
        }

        $key = $type.'|'.Str::lower($name);
        $aliases[$key] = [
            'name' => $name,
            'type' => $type,
            'source' => $source,
        ];
    }

    /**
     * @return list<string>
     */
    private function ageRatingNames(?string $value): array
    {
        $ratings = [];

        foreach ($this->valueList($value) as $name) {
            if (preg_match('/\b(\d{1,2})\s*\+?\b/u', $name, $matches) !== 1) {
                continue;
            }

            $ratings[$matches[1].'+'] = $matches[1].'+';
        }

        return array_values($ratings);
    }

    /**
     * @return list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>
     */
    private function seasons(DOMXPath $xpath, string $baseUrl): array
    {
        $seasons = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $sourceUrl = $href ? $this->normalizeRelative($href, $baseUrl) : null;

            if ($sourceUrl === null || ! $this->isDirectSeasonvarSeasonUrl($sourceUrl)) {
                continue;
            }

            $rawText = $this->stringValue($node->textContent) ?? '';
            $text = $this->cleanSeasonTitle($rawText);
            $releaseStatus = $this->seasonReleaseStatus($rawText);
            $number = $this->seasonNumberFromUrl($sourceUrl) ?? $this->seasonNumber($text);

            if ($number === null && $seasons === []) {
                $number = 1;
            }

            if ($number === null || $number <= 0) {
                continue;
            }

            $seasons[$number] = [
                'number' => $number,
                'title' => $this->safeSeasonTitle($text, $number),
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

    private function safeSeasonTitle(string $title, int $number): string
    {
        if ($title === '' || $this->isSuspiciousSeasonTitle($title)) {
            return "Сезон {$number}";
        }

        return $title;
    }

    private function isSuspiciousSeasonTitle(string $title): bool
    {
        return Str::startsWith($title, '...')
            || Str::length($title) > 220
            || (preg_match('/[.!?].+[.!?]/u', $title) === 1 && preg_match('/\b(?:сезон|season|sezon)\b/iu', $title) !== 1);
    }

    private function isDirectSeasonvarSeasonUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $host = Str::lower($parts['host']);
        $path = $parts['path'] ?? '';

        return in_array($host, ['seasonvar.ru', 'www.seasonvar.ru'], true)
            && preg_match('~^/serial-\d+-[^/]+-0*\d{1,4}-+(?:season|sezon)\.html$~iu', $path) === 1;
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
        $value = urldecode($value);

        foreach ([
            '/(?:^|[-_\/])0*(\d{1,3})(?:[-_]*(?:season|sezon|сезон))\b/iu',
            '/(?:season|sezon|сезон)[-_]*0*(\d{1,3})\b/iu',
            '/[_-]s0*(\d{1,3})\b/iu',
        ] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $number = (int) $matches[1];

                return $number > 0 ? $number : null;
            }
        }

        return null;
    }

    /**
     * @return list<array{season_number: int, number: int, title: string|null, source_url: string|null}>
     */
    private function episodes(string $html, string $baseUrl, int $seasonNumber): array
    {
        $episodes = [];
        $payload = $this->episodePayload($html);
        $decoded = $payload !== null ? json_decode($payload, true) : null;

        if (is_array($decoded)) {
            $this->collectEpisodesFromValue($decoded, $baseUrl, $seasonNumber, $episodes);
        }

        if ($episodes === []) {
            foreach ($this->fallbackEpisodeNumbers($html) as $number) {
                $episodes[$number] = [
                    'season_number' => $seasonNumber,
                    'number' => $number,
                    'title' => $number.' серия',
                    'source_url' => $baseUrl.'#'.$number.'_seriya',
                ];
            }
        }

        ksort($episodes);

        return array_values($episodes);
    }

    private function episodePayload(string $html): ?string
    {
        if (preg_match('/var\s+arEpisodes\s*=\s*/u', $html, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $matches[0][1] + strlen($matches[0][0]);

        return $this->balancedJavascriptValue($html, $start);
    }

    private function balancedJavascriptValue(string $text, int $start): ?string
    {
        $length = strlen($text);

        while ($start < $length && ctype_space($text[$start])) {
            $start++;
        }

        if ($start >= $length || ! in_array($text[$start], ['[', '{'], true)) {
            return null;
        }

        $stack = [];
        $stringQuote = null;
        $escaped = false;

        for ($position = $start; $position < $length; $position++) {
            $char = $text[$position];

            if ($stringQuote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === $stringQuote) {
                    $stringQuote = null;
                }

                continue;
            }

            if (in_array($char, ['"', "'"], true)) {
                $stringQuote = $char;

                continue;
            }

            if ($char === '[' || $char === '{') {
                $stack[] = $char;

                continue;
            }

            if ($char !== ']' && $char !== '}') {
                continue;
            }

            $open = array_pop($stack);

            if (($char === ']' && $open !== '[') || ($char === '}' && $open !== '{')) {
                return null;
            }

            if ($stack === []) {
                return substr($text, $start, $position - $start + 1);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{season_number: int, number: int, title: string|null, source_url: string|null}>  $episodes
     */
    private function collectEpisodesFromValue(mixed $value, string $baseUrl, int $seasonNumber, array &$episodes, string|int|null $key = null): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['n']) && is_numeric($value['n'])) {
            $number = (int) $value['n'];

            if ($number <= 0) {
                return;
            }

            $episodeSlug = is_string($key) && $key !== '' ? $key : $number.'_seriya';
            $episodeTitle = $this->firstNonEmpty([
                $value['title'] ?? null,
                $value['name'] ?? null,
                $value['t'] ?? null,
            ]);
            $episodes[$number] = [
                'season_number' => $seasonNumber,
                'number' => $number,
                'title' => $episodeTitle ?? $number.' серия',
                'source_url' => $baseUrl.'#'.$episodeSlug,
            ];

            return;
        }

        foreach ($value as $childKey => $childValue) {
            $this->collectEpisodesFromValue($childValue, $baseUrl, $seasonNumber, $episodes, $childKey);
        }
    }

    /**
     * @return list<int>
     */
    private function fallbackEpisodeNumbers(string $html): array
    {
        if (preg_match_all('/["\']?n["\']?\s*:\s*["\']?(\d{1,3})["\']?/iu', $html, $matches) !== 1) {
            return [];
        }

        return collect($matches[1])
            ->map(fn (string $number): int => (int) $number)
            ->filter(fn (int $number): bool => $number > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>
     */
    private function mediaCandidates(string $html, DOMXPath $xpath, string $baseUrl, int $seasonNumber): array
    {
        $items = [];

        foreach ($xpath->query('//*[@src or @href or @data or @data-src or @data-file or @data-url]') ?: [] as $node) {
            foreach (['src', 'href', 'data', 'data-src', 'data-file', 'data-url'] as $attribute) {
                $value = $node->attributes?->getNamedItem($attribute)?->nodeValue;

                if ($value === null) {
                    continue;
                }

                $this->addMediaCandidate($items, $value, $baseUrl, $this->mediaTitleFromNode($node), $seasonNumber);
            }
        }

        foreach ($this->mediaUrlsFromText($html) as $url) {
            $this->addMediaCandidate($items, $url, $baseUrl, null, $seasonNumber);
        }

        foreach ($this->seasonvarPlaylistUrls($html) as $url) {
            $this->addSeasonvarPlaylistCandidate($items, $url, $baseUrl, $seasonNumber);
        }

        return array_values($items);
    }

    /**
     * @param  array<string, array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $items
     */
    private function addMediaCandidate(array &$items, string $rawUrl, string $baseUrl, ?string $title, int $seasonNumber): void
    {
        $url = $this->cleanMediaUrl($rawUrl);

        if ($url === null || ! $this->looksLikeMediaUrl($url)) {
            return;
        }

        $normalizedUrl = $this->normalizeRelative($url, $baseUrl);

        if (! $this->looksLikeMediaUrl($normalizedUrl)) {
            return;
        }

        $numbers = $this->mediaNumbers($normalizedUrl.' '.$title, $seasonNumber);
        $key = Str::lower($normalizedUrl);
        $displayTitle = $this->firstNonEmpty([
            $title,
            $this->fileNameFromUrl($normalizedUrl),
        ]);
        $candidate = [
            'url' => $normalizedUrl,
            'title' => $displayTitle,
            'season_number' => $numbers['season_number'],
            'episode_number' => $numbers['episode_number'],
            'source_url' => $baseUrl,
            'kind' => $this->mediaKind($normalizedUrl),
        ];

        if (! isset($items[$key]) || ($items[$key]['episode_number'] === null && $candidate['episode_number'] !== null)) {
            $items[$key] = $candidate;
        }
    }

    /**
     * @param  array<string, array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $items
     */
    private function addSeasonvarPlaylistCandidate(array &$items, string $rawUrl, string $baseUrl, int $seasonNumber): void
    {
        $url = $this->cleanMediaUrl($rawUrl);

        if ($url === null || ! $this->looksLikeSeasonvarPlaylistUrl($url)) {
            return;
        }

        $normalizedUrl = $this->normalizeRelative($url, $baseUrl);

        if (! $this->looksLikeSeasonvarPlaylistUrl($normalizedUrl)) {
            return;
        }

        $key = Str::lower($normalizedUrl);
        $items[$key] ??= [
            'url' => $normalizedUrl,
            'title' => 'Плейлист Seasonvar',
            'season_number' => $seasonNumber,
            'episode_number' => null,
            'source_url' => $baseUrl,
            'kind' => 'seasonvar_playlist',
        ];
    }

    private function mediaTitleFromNode(DOMNode $node): ?string
    {
        foreach (['title', 'alt', 'data-title', 'aria-label'] as $attribute) {
            $value = $node->attributes?->getNamedItem($attribute)?->nodeValue;
            $title = $this->stringValue($value);

            if ($title !== null) {
                return $title;
            }
        }

        $text = $this->stringValue($node->textContent);

        return $text !== null && Str::length($text) <= 160 ? $text : null;
    }

    /**
     * @return list<string>
     */
    private function mediaUrlsFromText(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['\/', '\u002F', '\x2F'], '/', $text);
        $extensions = implode('|', array_map(fn (string $extension): string => preg_quote($extension, '~'), self::MEDIA_EXTENSIONS));
        $pattern = '~(?:(?:https?:)?//|/|[A-Za-z0-9._-]+/)?[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]+\.(?:'.$extensions.')(?:\?[A-Za-z0-9._\~:/?#\[\]@!$&()*+,;=%-]*)?~iu';

        if (preg_match_all($pattern, $text, $matches) !== 1) {
            return [];
        }

        return collect($matches[0])
            ->map(fn (string $url): string => trim($url, "\"'()[]{};,"))
            ->filter(fn (string $url): bool => $url !== '')
            ->unique(fn (string $url): string => Str::lower($url))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function seasonvarPlaylistUrls(string $html): array
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['\/', '\u002F', '\x2F'], '/', $text);

        $matchesCount = preg_match_all('~[\'"](?<url>(?:(?:https?:)?//[^\'"]+)?/playls2/[^\'"]*?/plist\.txt(?:\?[^\'"]*)?)[\'"]~iu', $text, $matches);

        if ($matchesCount === false || $matchesCount === 0) {
            return [];
        }

        return collect($matches['url'])
            ->map(fn (string $url): string => trim($url))
            ->filter(fn (string $url): bool => $url !== '')
            ->unique(fn (string $url): string => Str::lower($url))
            ->values()
            ->all();
    }

    private function cleanMediaUrl(string $url): ?string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = str_replace(['\/', '\u002F', '\x2F'], '/', $url);
        $url = trim($url, " \t\n\r\0\x0B\"'()[]{};,");

        return $url !== '' ? $url : null;
    }

    private function looksLikeMediaUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::MEDIA_EXTENSIONS, true);
    }

    private function looksLikeSeasonvarPlaylistUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~/playls2/.+?/plist\.txt$~iu', $path) === 1;
    }

    /**
     * @return array{season_number: int|null, episode_number: int|null}
     */
    private function mediaNumbers(string $value, int $fallbackSeasonNumber): array
    {
        $value = $this->normalizeMediaNumberText($value);
        $seasonNumber = null;
        $episodeNumber = null;
        $patterns = [
            '/\bs(?<season>\d{1,2})\s*e(?<episode>\d{1,3})\b/iu',
            '/\b(?<season>\d{1,2})x(?<episode>\d{1,3})\b/iu',
            '/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\D{0,30}(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)?/iu',
            '/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\D{0,30}(?<season>\d{1,2})\s*(?:сезон|sezon|season)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $seasonNumber = (int) $matches['season'];
                $episodeNumber = (int) $matches['episode'];

                break;
            }
        }

        if ($seasonNumber === null && preg_match('/(?<season>\d{1,2})\s*(?:сезон|sezon|season)\b/iu', $value, $matches) === 1) {
            $seasonNumber = (int) $matches['season'];
        }

        if ($episodeNumber === null) {
            foreach ([
                '/(?:^|[^\d])(?<episode>\d{1,3})[_\-\s]*(?:серия|seriya|episode|ep)(?:[^\d]|$)/iu',
                '/(?:серия|seriya|episode|ep)[_\-\s]*(?<episode>\d{1,3})(?:[^\d]|$)/iu',
                '/(?:^|[^\d])e(?<episode>\d{1,3})(?:[^\d]|$)/iu',
                '/[#?&](?:episode|seriya|e)=(?<episode>\d{1,3})(?:[^\d]|$)/iu',
            ] as $pattern) {
                if (preg_match($pattern, $value, $matches) === 1) {
                    $episodeNumber = (int) $matches['episode'];

                    break;
                }
            }
        }

        return [
            'season_number' => $seasonNumber ?: $fallbackSeasonNumber,
            'episode_number' => $episodeNumber ?: null,
        ];
    }

    private function normalizeMediaNumberText(string $value): string
    {
        $value = urldecode($value);
        $value = str_replace(['_', '.', '-'], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function mediaKind(string $url): string
    {
        $extension = Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, ['m3u', 'm3u8'], true) ? 'playlist' : 'file';
    }

    private function fileNameFromUrl(string $url): string
    {
        $fileName = basename((string) parse_url($url, PHP_URL_PATH));

        return urldecode($fileName !== '' ? $fileName : 'видео');
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function taxonomies(DOMXPath $xpath, string $baseUrl, array $structuredData, array $infoFields): array
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

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Жанр'])) as $name) {
            $key = 'genre|'.Str::lower($name);
            $items[$key] = ['type' => 'genre', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Страна'])) as $name) {
            $key = 'country|'.Str::lower($name);
            $items[$key] = ['type' => 'country', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->ageRatingNames($this->firstInfoField($infoFields, ['Ограничение'])) as $name) {
            $key = 'age_rating|'.Str::lower($name);
            $items[$key] = ['type' => 'age_rating', 'name' => $name, 'source_url' => null];
        }

        foreach (['Перевод', 'Озвучка'] as $label) {
            foreach ($this->valueList($this->firstInfoField($infoFields, [$label])) as $name) {
                $key = 'translation|'.Str::lower($name);
                $items[$key] = ['type' => 'translation', 'name' => $name, 'source_url' => null];
            }
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Статус'])) as $name) {
            $key = 'status|'.Str::lower($name);
            $items[$key] = ['type' => 'status', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Канал'])) as $name) {
            $key = 'network|'.Str::lower($name);
            $items[$key] = ['type' => 'network', 'name' => $name, 'source_url' => null];
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Студия'])) as $name) {
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

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Режиссер', 'Режиссёр'])) as $name) {
            $key = 'director|'.Str::lower($name);
            $items[$key] = ['type' => 'director', 'name' => $name, 'source_url' => null];
        }

        foreach (['В ролях', 'Актеры', 'Актёры'] as $label) {
            foreach ($this->valueList($this->firstInfoField($infoFields, [$label])) as $name) {
                $key = 'actor|'.Str::lower($name);
                $items[$key] = ['type' => 'actor', 'name' => $name, 'source_url' => null];
            }
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

    /**
     * @return list<array{author: string|null, body: string, published_at: string|null}>
     */
    private function reviews(DOMXPath $xpath): array
    {
        $reviews = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-review-post ")]') ?: [] as $node) {
            $body = $this->stringValue($node->textContent);

            if ($body === null) {
                continue;
            }

            $date = $this->reviewDate($body);

            if ($date !== null) {
                $body = Str::squish(str_replace($date['raw'], '', $body));
            }

            if ($body === '' || Str::length($body) < 40) {
                continue;
            }

            $key = hash('sha256', Str::lower($body));
            $reviews[$key] = [
                'author' => null,
                'body' => $body,
                'published_at' => $date['date'] ?? null,
            ];
        }

        return array_values($reviews);
    }

    /**
     * @return array{raw: string, date: string}|null
     */
    private function reviewDate(string $body): ?array
    {
        if (preg_match('/(?<raw>\b(?<day>\d{1,2})\.(?<month>\d{1,2})\.(?<year>\d{4})(?:\s+\d{1,2}:\d{2})?\b)/u', $body, $matches) !== 1) {
            return null;
        }

        return [
            'raw' => $matches['raw'],
            'date' => sprintf('%04d-%02d-%02d', (int) $matches['year'], (int) $matches['month'], (int) $matches['day']),
        ];
    }

    private function infoField(DOMXPath $xpath, string $label): ?string
    {
        return $this->firstInfoField($this->infoFields($xpath), [$label]);
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
