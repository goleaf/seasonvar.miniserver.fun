<?php

namespace App\Services\Seasonvar;

use DOMDocument;
use DOMXPath;
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
     *     seasons: list<array{number: int, title: string|null, source_url: string|null}>,
     *     taxonomies: list<array{type: string, name: string, source_url: string|null}>
     * }
     */
    public function parse(string $html, string $url): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);

        $title = $this->firstText($xpath, [
            '//meta[@property="og:title"]/@content',
            '//h1',
            '//title',
        ]) ?? 'Untitled';

        $title = $this->cleanTitle($title);
        $description = $this->firstText($xpath, [
            '//meta[@name="description"]/@content',
            '//meta[@property="og:description"]/@content',
        ]);

        $posterUrl = $this->firstText($xpath, [
            '//meta[@property="og:image"]/@content',
            '//img[contains(@class, "poster")]/@src',
            '//img[contains(@class, "cover")]/@src',
        ]);

        $year = $this->extractYear($title.' '.$description);

        return [
            'title' => $title,
            'original_title' => null,
            'type' => 'serial',
            'year' => $year,
            'description' => $description,
            'poster_url' => $posterUrl ? $this->normalizeRelative($posterUrl, $url) : null,
            'external_id' => $this->seasonvarUrl->externalSerialId($url),
            'seasons' => $this->seasons($xpath, $url),
            'taxonomies' => $this->taxonomies($xpath, $url),
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

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', $title) ?: $title;
        $title = preg_replace('/\s*[-|]\s*(смотреть|seasonvar).*$/iu', '', $title) ?: $title;

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

        foreach ($xpath->query('//a[contains(@href, "season.html") or contains(@href, "-season")]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $text = trim($node->textContent);
            $number = null;

            if ($href !== null && preg_match('/-(\d+)-season/i', $href, $matches) === 1) {
                $number = (int) $matches[1];
            }

            if ($number === null && preg_match('/(\d+)/', $text, $matches) === 1) {
                $number = (int) $matches[1];
            }

            if ($number === null || $number <= 0) {
                continue;
            }

            $sourceUrl = $href ? $this->normalizeRelative($href, $baseUrl) : null;
            $seasons[$number] = [
                'number' => $number,
                'title' => $text !== '' ? $text : "Season {$number}",
                'source_url' => $sourceUrl,
            ];
        }

        if ($seasons === []) {
            $seasons[1] = [
                'number' => 1,
                'title' => 'Season 1',
                'source_url' => $baseUrl,
            ];
        }

        ksort($seasons);

        return array_values($seasons);
    }

    /**
     * @return list<array{type: string, name: string, source_url: string|null}>
     */
    private function taxonomies(DOMXPath $xpath, string $baseUrl): array
    {
        $items = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = $node->attributes?->getNamedItem('href')?->nodeValue;
            $name = trim($node->textContent);

            if ($href === null || $name === '') {
                continue;
            }

            $type = match (true) {
                str_contains($href, 'genre') => 'genre',
                str_contains($href, 'country') => 'country',
                str_contains($href, 'actor') => 'actor',
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
}
