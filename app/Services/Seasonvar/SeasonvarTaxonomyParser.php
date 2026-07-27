<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarPageType;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SeasonvarTaxonomyParser
{
    public function __construct(
        private SeasonvarUrl $seasonvarUrl,
        private CatalogRelationNameSanitizer $relationNames,
        private SeasonvarRelationMetadataNormalizer $relationMetadata,
    ) {}

    /**
     * @param  array<string, mixed>  $structuredData
     * @param  array<string, list<string>>  $infoFields
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    public function parse(
        DOMXPath $xpath,
        string $baseUrl,
        array $structuredData,
        array $infoFields,
    ): array {
        $items = [];

        foreach ($this->structuredTaxonomies($structuredData) as $item) {
            $this->add($items, $item['type'], $item['name'], $item['source_url']);
        }

        foreach ($this->valueList($this->firstText($xpath, ['//*[@itemprop="genre"]'])) as $name) {
            $this->add($items, 'genre', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Жанр']) as $name) {
            $this->add($items, 'genre', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Страна']) as $name) {
            $this->add($items, 'country', $name, null);
        }

        foreach ($this->ageRatingNames($this->firstInfoField($infoFields, ['Ограничение'])) as $name) {
            $this->add($items, 'age_rating', $name, null);
        }

        foreach (['Перевод', 'Озвучка'] as $label) {
            foreach ($this->infoValueList($infoFields, [$label]) as $name) {
                $this->add($items, 'translation', $name, null);
            }
        }

        foreach ($this->officialTranslationNames($xpath) as $name) {
            $this->add($items, 'translation', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Статус']) as $name) {
            $this->add($items, 'status', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Телеканал', 'Канал']) as $name) {
            $this->add($items, 'network', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Студии', 'Студия']) as $name) {
            $this->add($items, 'studio', $name, null);
        }

        foreach ($this->officialDescriptionTaxonomies($xpath) as $item) {
            $this->add($items, $item['type'], $item['name'], null);
        }

        foreach ($this->seasonListTranslations($xpath) as $name) {
            $this->add($items, 'translation', $name, null);
        }

        foreach ($this->valueList(
            $this->firstText($xpath, ['//*[@itemprop="directors"]//*[@itemprop="name"]']),
        ) as $name) {
            $this->add($items, 'director', $name, null);
        }

        foreach ($this->infoValueList($infoFields, ['Режиссер', 'Режиссёр']) as $name) {
            $this->add($items, 'director', $name, null);
        }

        foreach (['В ролях', 'Актеры', 'Актёры'] as $label) {
            foreach ($this->infoValueList($infoFields, [$label]) as $name) {
                $this->add($items, 'actor', $name, null);
            }
        }

        foreach ([
            '//*[@data-info="actor"]//*[@itemprop="name"]',
            '//*[@itemprop="actor"]//*[@itemprop="name"]',
            '//*[@itemprop="actors"]//*[@itemprop="name"]',
        ] as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $name = $this->stringValue($node->textContent);

                if ($name !== null) {
                    $this->add($items, 'actor', $name, null);
                }
            }
        }

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $name = trim($node->textContent);

            if ($href === null || $name === '') {
                continue;
            }

            $type = $this->relationTypeFromHref($href);

            if ($type !== null) {
                $this->add(
                    $items,
                    $type,
                    $name,
                    $this->normalizeRelative($href, $baseUrl),
                );
            }
        }

        foreach ($this->tagTaxonomies($xpath, $baseUrl) as $item) {
            $this->add($items, 'tag', $item['name'], $item['source_url']);
            $network = $this->relationMetadata->curatedNetwork($item['name']);

            if ($network !== null) {
                $this->add($items, 'network', $network, $item['source_url']);
            }
        }

        if ($this->hasSubtitles($xpath)) {
            $this->add($items, 'tag', 'субтитры', null);
        }

        return array_values($items);
    }

    private function relationTypeFromHref(string $href): ?string
    {
        $path = '/'.ltrim(
            Str::lower(rawurldecode((string) parse_url($href, PHP_URL_PATH))),
            '/',
        );

        return match (true) {
            preg_match('~^/(?:genre|janr|zhanr)(?:/|-)~u', $path) === 1 => 'genre',
            preg_match('~^/(?:country|strana)(?:/|-)~u', $path) === 1 => 'country',
            preg_match('~^/(?:actor|akter)(?:/|-)~u', $path) === 1 => 'actor',
            preg_match('~^/(?:director|rezhisser)(?:/|-)~u', $path) === 1 => 'director',
            default => null,
        };
    }

    /**
     * @param  array<string, array{type: string, name: string, source_url: string|null}>  $items
     */
    private function add(
        array &$items,
        string $type,
        string $name,
        ?string $sourceUrl,
    ): void {
        $name = match ($type) {
            'country' => $this->relationMetadata->country($name),
            'status' => $this->relationMetadata->status($name),
            'translation' => $this->relationMetadata->translation($name),
            default => $name,
        };

        if ($name === null) {
            return;
        }

        $name = $this->relationNames->normalize($name);

        if (! $this->relationNames->isValid($type, $name)) {
            return;
        }

        $items[$type.'|'.Str::lower($name)] = [
            'type' => $type,
            'name' => $name,
            'source_url' => $sourceUrl,
        ];
    }

    /** @return list<string> */
    private function officialTranslationNames(DOMXPath $xpath): array
    {
        $translations = [];
        $query = '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-trans ")]//*[@data-click="translate"]';

        foreach ($xpath->query($query) ?: [] as $node) {
            $values = [$node->textContent];

            foreach (['data-translate', 'data-value', 'title'] as $attribute) {
                $values[] = $node->attributes?->getNamedItem($attribute)?->nodeValue;
            }

            foreach ($values as $value) {
                $name = $this->relationMetadata->translation(
                    is_string($value) ? $value : null,
                );

                if ($name !== null && $this->relationNames->isValid('translation', $name)) {
                    $translations[Str::lower($name)] = $name;
                }
            }
        }

        return array_values($translations);
    }

    /** @return list<array{type: string, name: string}> */
    private function officialDescriptionTaxonomies(DOMXPath $xpath): array
    {
        $items = [];
        $query = '//*[(
            @itemprop="description"
            or contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_text ")
        ) and not(ancestor-or-self::*[
            contains(concat(" ", normalize-space(@class), " "), " svc_comment ")
            or contains(concat(" ", normalize-space(@class), " "), " pgs-review-post ")
            or contains(concat(" ", normalize-space(@class), " "), " comment ")
        ])]';

        foreach ($xpath->query($query) ?: [] as $node) {
            $html = $node->ownerDocument?->saveHTML($node) ?: $node->textContent;
            $html = preg_replace(
                '~<br\s*/?>|</(?:p|div|li|section|article|h[1-6])>~iu',
                "\n",
                $html,
            ) ?? $html;
            $text = str_replace(
                "\xc2\xa0",
                ' ',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );

            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $line = Str::squish($line);

                if (preg_match(
                    '/^(?<label>Студи(?:я|и)|Телеканал|Канал|Статус)\s*:\s*(?<value>[^\r\n]{1,120})$/iu',
                    $line,
                    $matches,
                ) !== 1) {
                    continue;
                }

                $type = match (Str::lower($matches['label'])) {
                    'студия', 'студии' => 'studio',
                    'телеканал', 'канал' => 'network',
                    'статус' => 'status',
                    default => null,
                };

                if ($type !== null) {
                    $items[] = ['type' => $type, 'name' => $matches['value']];
                }
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function seasonListTranslations(DOMXPath $xpath): array
    {
        $translations = [];

        foreach (
            $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]//a',
            ) ?: [] as $node
        ) {
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

    /** @return list<string> */
    public function translationNamesFromText(string $text): array
    {
        $count = preg_match_all('/\(([^()]{2,80})\)/u', $text, $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        $translations = [];

        foreach ($matches[1] as $match) {
            $name = $this->stringValue($match);

            if ($name === null
                || preg_match('/^\d{2}\.\d{2}\.\d{4}/u', $name) === 1
                || preg_match('/(?:сер(?:ия|ии|ий)|из|\?\?)/iu', $name) === 1
                || ! $this->relationNames->isValid('translation', $name)
            ) {
                continue;
            }

            $translations[Str::lower($name)] = $name;
        }

        return array_values($translations);
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function tagTaxonomies(DOMXPath $xpath, string $baseUrl): array
    {
        $items = [];

        foreach (
            $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " b-taglist ")]//a[@href]',
            ) ?: [] as $node
        ) {
            $name = $this->stringValue($node->textContent);
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;

            if ($name === null || $href === null || mb_strlen($name) > 80) {
                continue;
            }

            $sourceUrl = $this->normalizeRelative($href, $baseUrl);

            if ($this->seasonvarUrl->pageType($sourceUrl) !== SeasonvarPageType::Tag) {
                continue;
            }

            $items[Str::lower($name)] = [
                'type' => 'tag',
                'name' => $name,
                'source_url' => $sourceUrl,
            ];
        }

        return array_values($items);
    }

    private function hasSubtitles(DOMXPath $xpath): bool
    {
        $query = '//*[contains(concat(" ", normalize-space(@class), " "), " pgs-sinfo_list ")]
            | //*[contains(concat(" ", normalize-space(@class), " "), " pgs-seaslist ")]
            | //*[contains(concat(" ", normalize-space(@class), " "), " pgs-trans ")]
            | //*[contains(concat(" ", normalize-space(@class), " "), " b-taglist ")]';

        foreach ($xpath->query($query) ?: [] as $node) {
            $text = $this->stringValue($node->textContent);

            if ($text !== null
                && preg_match('/(?:субтитр|subtitles?|subs?)/iu', $text) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $structuredData
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

        foreach ($this->structuredEntityNames(
            Arr::get($structuredData, 'productionCompany'),
        ) as $name) {
            $items[] = ['type' => 'studio', 'name' => $name, 'source_url' => null];
        }

        return $items;
    }

    /** @return list<string> */
    private function structuredEntityNames(mixed $value): array
    {
        $items = [];
        $entities = is_array($value) && ! array_is_list($value)
            ? [$value]
            : Arr::wrap($value);

        foreach ($entities as $item) {
            $name = is_array($item)
                ? $this->stringValue(Arr::get($item, 'name'))
                : $this->stringValue($item);

            if ($name !== null) {
                $items[Str::lower($name)] = $name;
            }
        }

        return array_values($items);
    }

    /** @return list<string> */
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

    /**
     * @param  array<string, list<string>>  $infoFields
     * @param  list<string>  $labels
     */
    private function firstInfoField(array $infoFields, array $labels): ?string
    {
        foreach ($labels as $label) {
            $value = $infoFields[$label][0] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $infoFields
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function infoValueList(array $infoFields, array $labels): array
    {
        $items = [];

        foreach ($labels as $label) {
            foreach ($infoFields[$label] ?? [] as $value) {
                foreach ($this->valueList($value) as $item) {
                    $items[Str::lower($item)] = $item;
                }
            }
        }

        return array_values($items);
    }

    /** @return list<string> */
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

    /** @param list<string> $queries */
    private function firstText(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = $nodes->item(0)?->nodeValue;
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
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $string = $this->stringValue($value[$key]);

            if ($string !== null) {
                return $string;
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

    private function normalizeRelative(string $url, string $baseUrl): string
    {
        try {
            return $this->seasonvarUrl->normalize($url, $baseUrl);
        } catch (InvalidArgumentException) {
            return $url;
        }
    }
}
