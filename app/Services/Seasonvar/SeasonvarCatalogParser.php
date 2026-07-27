<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\CatalogPublicationType;
use App\Support\CatalogTitleDisplayName;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SeasonvarCatalogParser
{
    public const METADATA_VERSION = 6;

    private const METADATA_PRESENCE_FIELDS = [
        'genres' => ['type' => 'genre', 'labels' => ['Жанр']],
        'countries' => ['type' => 'country', 'labels' => ['Страна']],
        'actors' => ['type' => 'actor', 'labels' => ['В ролях', 'Актеры', 'Актёры']],
        'directors' => ['type' => 'director', 'labels' => ['Режиссер', 'Режиссёр']],
        'age_ratings' => ['type' => 'age_rating', 'labels' => ['Ограничение']],
        'translations' => ['type' => 'translation', 'labels' => ['Перевод', 'Озвучка']],
        'statuses' => ['type' => 'status', 'labels' => ['Статус']],
        'networks' => ['type' => 'network', 'labels' => ['Телеканал', 'Канал']],
        'studios' => ['type' => 'studio', 'labels' => ['Студии', 'Студия']],
        'tags' => ['type' => 'tag', 'labels' => []],
    ];

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
        'Телеканал',
        'Канал',
        'Студии',
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

    public function __construct(
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarSourceAvailabilityDetector $sourceAvailability,
        private readonly SeasonvarStructuredDataParser $structuredDataParser,
        private readonly SeasonvarEpisodeScriptParser $episodeScriptParser,
        private readonly SeasonvarMediaCandidateParser $mediaCandidateParser,
        private readonly SeasonvarTaxonomyParser $taxonomyParser,
    ) {}

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
     *     recommendation_signals: list<array{source: string, signal_type: string, signal_key: string, signal_value: string|null, weight: int}>,
     *     aliases: list<array{name: string, type: string, source: string}>,
     *     reviews: list<array{author: string|null, body: string, published_at: string|null}>,
     *     parse_meta: array{info_labels: list<string>, has_info_list: bool, has_season_list: bool, has_episode_script: bool, provider_availability_status: string|null, section_presence: array<string, string>}
     * }
     */
    public function parse(string $html, string $url): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $structuredData = $this->structuredDataParser->parse($xpath);
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

        $currentSeasonNumber = $this->seasonNumberFromUrl($url) ?? $this->seasonNumber($title) ?? 1;
        $seasons = $this->seasons($xpath, $url, $currentSeasonNumber);
        $taxonomies = $this->taxonomyParser->parse(
            $xpath,
            $url,
            $structuredData,
            $infoFields,
        );
        $ratings = $this->ratings($infoFields);
        $episodes = $this->episodeScriptParser->parse($html, $url, $currentSeasonNumber);
        $media = $this->mediaCandidateParser->parse(
            $html,
            $xpath,
            $url,
            $currentSeasonNumber,
        );
        $aliases = $this->aliases($infoFields, $title, $originalTitle);
        $reviews = $this->reviews($xpath);
        $parseMeta = $this->parseMeta(
            $xpath,
            $html,
            $infoFields,
            $episodes,
            $media,
            $aliases,
            $ratings,
            $reviews,
        );

        return [
            'title' => $title,
            'original_title' => $originalTitle,
            'type' => $this->publicationType($taxonomies),
            'year' => $year,
            'description' => $description,
            'poster_url' => $posterUrl ? $this->normalizeRelative($posterUrl, $url) : null,
            'external_id' => $this->seasonvarUrl->externalSerialId($url),
            'current_season_number' => $currentSeasonNumber,
            'seasons' => $seasons,
            'episodes' => $episodes,
            'media' => $media,
            'taxonomies' => $taxonomies,
            'ratings' => $ratings,
            'recommendation_signals' => [],
            'aliases' => $aliases,
            'reviews' => $reviews,
            'parse_meta' => $parseMeta,
        ];
    }

    /** @param list<array<string, mixed>> $taxonomies */
    private function publicationType(array $taxonomies): string
    {
        $genres = collect($taxonomies)
            ->where('type', 'genre')
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->map(fn (string $name): string => Str::of($name)
                ->lower()
                ->replace(['ё', '–', '—', '−'], ['е', '-', '-', '-'])
                ->replaceMatches('/[\s_-]+/u', '-')
                ->trim('-')
                ->toString());

        if ($genres->contains('аниме')) {
            return CatalogPublicationType::Anime->value;
        }

        if ($genres->contains('реалити-шоу')) {
            return CatalogPublicationType::Show->value;
        }

        if ($genres->contains('документальные')) {
            return CatalogPublicationType::Documentary->value;
        }

        return CatalogPublicationType::Serial->value;
    }

    /**
     * @param  list<array<string, mixed>>  $taxonomies
     * @param  array<string, mixed>  $parseMeta
     * @return array<string, 'present'|'rejected_invalid'|'absent_in_source'|'partial_response'|array<string, string>>
     */
    public function metadataPresence(array $taxonomies, array $parseMeta): array
    {
        $presentTypes = collect($taxonomies)->pluck('type')->unique();
        $infoLabels = $parseMeta['info_labels'] ?? [];
        $labels = collect(is_array($infoLabels) ? $infoLabels : [])
            ->filter(fn (mixed $label): bool => is_string($label))
            ->map(fn (string $label): string => Str::lower($label));
        $metadataState = (string) data_get($parseMeta, 'section_presence.metadata', 'unknown');
        $metadataIsAuthoritative = in_array($metadataState, ['complete', 'absent'], true);

        return collect(self::METADATA_PRESENCE_FIELDS)
            ->mapWithKeys(function (array $definition, string $field) use ($presentTypes, $labels, $metadataIsAuthoritative): array {
                if ($presentTypes->contains($definition['type'])) {
                    return [$field => 'present'];
                }

                $hadSourceValue = collect($definition['labels'])
                    ->map(fn (string $label): string => Str::lower($label))
                    ->intersect($labels)
                    ->isNotEmpty();

                if ($hadSourceValue) {
                    return [$field => 'rejected_invalid'];
                }

                return [$field => $metadataIsAuthoritative ? 'absent_in_source' : 'partial_response'];
            })
            ->put('_sections', $parseMeta['section_presence'] ?? [])
            ->all();
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

            $value = trim($nodes->item(0)->textContent);

            if ($value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    private function hasNodes(DOMXPath $xpath, string $query): bool
    {
        $nodes = $xpath->query($query);

        return $nodes !== false && $nodes->length > 0;
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

        $status['translation_name'] = Arr::first(
            $this->taxonomyParser->translationNamesFromText($normalized),
        );

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
     * @param  list<array<string, mixed>>  $episodes
     * @param  list<array<string, mixed>>  $media
     * @param  list<array<string, mixed>>  $aliases
     * @param  list<array<string, mixed>>  $ratings
     * @param  list<array<string, mixed>>  $reviews
     * @return array{info_labels: list<string>, has_info_list: bool, has_season_list: bool, has_episode_script: bool, provider_availability_status: string|null, section_presence: array<string, string>}
     */
    private function parseMeta(
        DOMXPath $xpath,
        string $html,
        array $infoFields,
        array $episodes,
        array $media,
        array $aliases,
        array $ratings,
        array $reviews,
    ): array {
        $hasInfoList = $this->hasNodes($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]');
        $hasSeasonList = $this->hasNodes($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]');
        $hasEpisodeScript = Str::contains($html, 'arEpisodes');
        $providerAvailability = $this->sourceAvailability->detect($html)?->value;
        $truncated = ! Str::contains(Str::lower($html), '</html>');
        $metadataState = $this->metadataSectionState($xpath, $hasInfoList, $infoFields);
        $seasonState = $this->seasonSectionState($xpath, $hasSeasonList);
        $episodeState = $this->episodeSectionState($html, $hasEpisodeScript, $episodes);
        $reviewNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-review-post ")]');
        $reviewsState = $reviewNodes === false || $reviewNodes->length === 0
            ? 'unknown'
            : ($reviews === [] ? 'invalid' : 'complete');

        if ($truncated) {
            $metadataState = $metadataState === 'complete' ? 'partial' : $metadataState;
            $seasonState = $seasonState === 'complete' ? 'partial' : $seasonState;
            $episodeState = $episodeState === 'complete' ? 'partial' : $episodeState;
            $reviewsState = $reviewsState === 'complete' ? 'partial' : $reviewsState;
        }

        return [
            'info_labels' => array_keys($infoFields),
            'has_info_list' => $hasInfoList,
            'has_season_list' => $hasSeasonList,
            'has_episode_script' => $hasEpisodeScript,
            'provider_availability_status' => $providerAvailability,
            'section_presence' => [
                'metadata' => $metadataState,
                'taxonomies' => $metadataState,
                'seasons' => $seasonState,
                'episodes' => $episodeState,
                'media' => $providerAvailability !== null
                    ? 'partial'
                    : ($media === [] ? 'unknown' : 'partial'),
                'aliases' => $this->dependentMetadataState($metadataState, $aliases),
                'ratings' => $this->dependentMetadataState($metadataState, $ratings),
                'recommendations' => 'unknown',
                'reviews' => $reviewsState,
            ],
        ];
    }

    /** @param array<string, list<string>> $infoFields */
    private function metadataSectionState(DOMXPath $xpath, bool $hasInfoList, array $infoFields): string
    {
        if (! $hasInfoList) {
            return 'unknown';
        }

        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]');
        $text = $nodes !== false
            ? collect(iterator_to_array($nodes))->map(fn (DOMNode $node): string => trim($node->textContent))->implode(' ')
            : '';

        if (trim($text) === '') {
            return 'absent';
        }

        return $infoFields === [] ? 'invalid' : 'complete';
    }

    private function seasonSectionState(DOMXPath $xpath, bool $hasSeasonList): string
    {
        if (! $hasSeasonList) {
            return 'unknown';
        }

        $links = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]//a[@href]');

        if ($links === false || $links->length === 0) {
            return 'absent';
        }

        foreach ($links as $link) {
            $href = $link->attributes?->getNamedItem('href')?->nodeValue;

            if (is_string($href) && preg_match('~(?:season|sezon|сезон)~iu', $href) === 1) {
                return 'complete';
            }
        }

        return 'invalid';
    }

    /** @param list<array<string, mixed>> $episodes */
    private function episodeSectionState(string $html, bool $hasEpisodeScript, array $episodes): string
    {
        if (! $hasEpisodeScript) {
            return 'unknown';
        }

        $payload = $this->episodeScriptParser->payload($html);

        if ($payload === null || ! is_array(json_decode($payload, true))) {
            return 'invalid';
        }

        return $episodes === [] ? 'absent' : 'complete';
    }

    /** @param list<array<string, mixed>> $items */
    private function dependentMetadataState(string $metadataState, array $items): string
    {
        if (! in_array($metadataState, ['complete', 'absent'], true)) {
            return $metadataState;
        }

        return $items === [] ? 'absent' : 'complete';
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
        $displayName = CatalogTitleDisplayName::from($title, $originalTitle);

        if ($originalTitle !== null) {
            $this->addAlias($aliases, $originalTitle, 'original', 'info');
        }

        foreach ($this->valueList($this->firstInfoField($infoFields, ['Альтернативное название'])) as $name) {
            $this->addAlias($aliases, $name, 'alternative', 'info');
        }

        foreach (explode('/', $title) as $name) {
            $this->addAlias($aliases, $name, 'source_title', 'title');
        }

        return collect($aliases)
            ->reject(fn (array $alias): bool => $displayName->contains($alias['name']))
            ->values()
            ->all();
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

        $key = CatalogTitleDisplayName::comparisonKey($name);

        if (isset($aliases[$key])) {
            return;
        }

        $aliases[$key] = [
            'name' => $name,
            'type' => $type,
            'source' => $source,
        ];
    }

    /**
     * @return list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>
     */
    private function seasons(DOMXPath $xpath, string $baseUrl, int $currentSeasonNumber): array
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

        if (! array_key_exists($currentSeasonNumber, $seasons)) {
            $releaseStatus = $this->emptySeasonReleaseStatus();

            $seasons[$currentSeasonNumber] = [
                'number' => $currentSeasonNumber,
                'title' => "Сезон {$currentSeasonNumber}",
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
            && preg_match('~^/serial-\d+-[^/]+(?:-0*\d{1,4}-+(?:season|sezon))?\.html$~iu', $path) === 1;
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
