<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportEvent;
use App\Models\SourcePage;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

final readonly class SeasonvarRssFreshnessImporter
{
    public function __construct(
        private SeasonvarUrl $urls,
        private SeasonvarDiscoveredPageStore $pages,
    ) {}

    /** @param (callable(string, array<string, mixed>): void)|null $progress */
    public function import(SourcePage $page, string $xml, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        $serialUrls = $this->serialUrls($xml, $page->url);
        $this->pages->store(
            $serialUrls,
            $page->url,
            max(1, (int) config('seasonvar.import.linked_serial_defer_minutes', 5)),
            $progress,
        );
        $hashes = collect($serialUrls)->map(fn (string $url): string => $this->urls->hash($url));

        if ($hashes->isNotEmpty()) {
            SourcePage::query()
                ->whereIn('url_hash', $hashes)
                ->where('page_type', SeasonvarPageType::Serial->value)
                ->where(function ($query): void {
                    $query->whereNull('import_claim_token')
                        ->orWhereNull('import_claim_expires_at')
                        ->orWhere('import_claim_expires_at', '<=', now());
                })
                ->update([
                    'import_status' => 'pending',
                    'retry_after_at' => null,
                    'updated_at' => now(),
                ]);
        }

        $context = [
            'page_type' => SeasonvarPageType::Rss->value,
            'linked_serial_urls_found' => count($serialUrls),
            'structured_fields' => ['linked_serial_urls'],
        ];
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $importRunId,
            'source_page_id' => $page->id,
            'event' => 'seasonvar-rss-freshness-recorded',
            'level' => 'info',
            'context' => $context,
        ]);

        if ($progress !== null) {
            $progress('seasonvar-rss-freshness-recorded', ['source_page_id' => $page->id, ...$context]);
        }

        return new SeasonvarPageHandlerResult(
            linkedSerialUrls: count($serialUrls),
            structuredFields: ['linked_serial_urls'],
        );
    }

    /** @return list<string> */
    private function serialUrls(string $xml, string $sourceUrl): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('Не удалось разобрать RSS Seasonvar.');
        }

        $limit = max(1, (int) config('seasonvar.import.max_linked_serial_urls', 100));
        $urls = [];

        foreach ((new DOMXPath($document))->query('//*[local-name()="item"]/*[local-name()="link"]') ?: [] as $node) {
            try {
                $url = $this->urls->normalize(trim($node->textContent), $sourceUrl);
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
}
