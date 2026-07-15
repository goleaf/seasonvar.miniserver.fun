<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarMetadataPageData;
use App\Enums\SeasonvarPageType;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class SeasonvarTaxonomyPageParser
{
    public function __construct(
        private SeasonvarUrl $urls,
        private SeasonvarTaxonomyIdentity $identity,
    ) {}

    public function parse(string $html, string $sourceUrl, SeasonvarPageType $pageType): SeasonvarMetadataPageData
    {
        $xpath = $this->xpath($html);
        $pageTitle = $this->nodeText($xpath, '//title');
        $displayName = $this->identity->displayName($this->nodeText($xpath, '//h1') ?? $pageTitle ?? '');

        if ($displayName === '') {
            throw new RuntimeException('Metadata-страница Seasonvar не содержит канонического названия.');
        }

        $canonical = $this->canonicalUrl($xpath, $sourceUrl, $pageType);
        $sourceSlug = rawurldecode(basename((string) parse_url($canonical, PHP_URL_PATH)));
        $linkedSerialUrls = $this->linkedSerialUrls($xpath, $sourceUrl);
        $sourceCount = $this->sourceCount($xpath);
        $missing = collect([
            $pageTitle === null ? 'page_title' : null,
            $linkedSerialUrls === [] ? 'linked_serial_urls' : null,
        ])->filter()->values()->all();

        return new SeasonvarMetadataPageData(
            pageType: $pageType,
            displayName: $displayName,
            normalizedName: $this->identity->comparisonKey($displayName),
            sourceSlug: Str::substr($sourceSlug, 0, 160),
            sourceUrl: $sourceUrl,
            canonicalSourceUrl: $canonical,
            pageTitle: $pageTitle !== null ? Str::limit($pageTitle, 255, '') : null,
            alphabetPosition: Str::upper(Str::substr($displayName, 0, 1)),
            sourceProvidedCount: $sourceCount,
            linkedSerialUrls: $linkedSerialUrls,
            sourceAliases: $this->identity->sourceAliases($displayName, $sourceSlug),
            missingDataFlags: $missing,
        );
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Не удалось разобрать metadata-страницу Seasonvar.');
        }

        return new DOMXPath($document);
    }

    private function nodeText(DOMXPath $xpath, string $query): ?string
    {
        $value = $this->firstNode($xpath, $query)?->textContent;
        $value = is_string($value) ? Str::squish(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';

        return $value !== '' ? $value : null;
    }

    private function canonicalUrl(DOMXPath $xpath, string $sourceUrl, SeasonvarPageType $pageType): string
    {
        $node = $this->firstNode($xpath, '//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]');
        $candidate = $node instanceof DOMElement ? trim($node->getAttribute('href')) : '';

        if ($candidate === '') {
            return $sourceUrl;
        }

        try {
            $canonical = $this->urls->normalize($candidate, $sourceUrl);
        } catch (Throwable) {
            return $sourceUrl;
        }

        return $this->urls->isAllowed($canonical) && $this->urls->pageType($canonical) === $pageType
            ? $canonical
            : $sourceUrl;
    }

    /** @return list<string> */
    private function linkedSerialUrls(DOMXPath $xpath, string $sourceUrl): array
    {
        $limit = max(1, (int) config('seasonvar.import.max_linked_serial_urls', 100));
        $urls = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            try {
                $url = $this->urls->normalize($node->getAttribute('href'), $sourceUrl);
            } catch (Throwable) {
                continue;
            }

            if (! $this->urls->isAllowed($url) || $this->urls->pageType($url) !== SeasonvarPageType::Serial) {
                continue;
            }

            $urls[$url] = $url;

            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_values($urls);
    }

    private function sourceCount(DOMXPath $xpath): ?int
    {
        $node = $this->firstNode($xpath, '//*[@data-source-count]');

        if (! $node instanceof DOMElement) {
            return null;
        }

        $value = filter_var($node->getAttribute('data-source-count'), FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function firstNode(DOMXPath $xpath, string $query): ?DOMNode
    {
        $nodes = $xpath->query($query);

        return $nodes === false ? null : $nodes->item(0);
    }
}
