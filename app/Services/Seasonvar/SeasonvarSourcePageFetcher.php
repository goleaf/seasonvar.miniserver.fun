<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarFetchedPage;
use App\Enums\SeasonvarPageType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Crawler\PoliteHttpClient;
use InvalidArgumentException;
use RuntimeException;

final class SeasonvarSourcePageFetcher
{
    public function __construct(
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * Fetch and persist only source crawl data. This boundary never writes catalog rows.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function fetch(
        SourcePage $page,
        ?int $importRunId = null,
        ?callable $progress = null,
    ): SeasonvarFetchedPage {
        $url = $this->seasonvarUrl->normalize($page->url);

        if (! $this->seasonvarUrl->isAllowed($url)) {
            throw new InvalidArgumentException('Разрешены только страницы каталога https://seasonvar.ru/.');
        }

        $source = $page->source;
        $crawlDelaySeconds = (int) $source->crawl_delay_seconds;
        $response = $this->httpClient->get(
            $url,
            $crawlDelaySeconds,
            $progress,
            $this->conditionalRequestHeaders($page),
        );

        if ($response->status() === 304) {
            if ($page->content_hash === null) {
                throw new RuntimeException('Seasonvar вернул 304 для страницы без сохраненного содержимого.');
            }

            $snapshot = $page->snapshots()
                ->where('content_hash', $page->content_hash)
                ->latest('captured_at')
                ->latest('id')
                ->first();

            if ($snapshot === null) {
                throw new RuntimeException('Seasonvar вернул 304, но сохраненный HTML-снимок страницы не найден.');
            }

            $page->update([
                'http_status' => 304,
                'last_crawled_at' => now(),
                'last_import_run_id' => $importRunId,
                'failure_count' => 0,
                'error_message' => null,
            ]);

            return new SeasonvarFetchedPage(
                sourcePageId: $page->id,
                body: $snapshot->html,
                contentHash: $page->content_hash,
                httpStatus: 304,
                contentChanged: false,
                snapshotId: $snapshot->id,
                notModified: true,
            );
        }

        $body = $response->body();
        $contentHash = hash('sha256', $body);
        $contentChanged = $page->content_hash !== $contentHash;

        $this->report($progress, 'page-response-received', [
            'source_page_id' => $page->id,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'body_bytes' => mb_strlen($body, '8bit'),
            'content_hash' => $contentHash,
            'content_changed' => $contentChanged,
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
        ]);

        $page->update([
            'http_status' => $response->status(),
            'content_hash' => $contentHash,
            'etag' => $response->header('ETag'),
            'last_modified_header' => $response->header('Last-Modified'),
            'last_crawled_at' => now(),
            'last_changed_at' => $contentChanged ? now() : $page->last_changed_at,
            'last_import_run_id' => $importRunId,
        ]);
        $snapshot = $this->storeSnapshot($page, $body, $contentHash, $response->status(), $importRunId);

        $this->report($progress, 'source-page-crawl-metadata-updated', [
            'source_page_id' => $page->id,
            'http_status' => $page->http_status,
            'content_hash' => $page->content_hash,
            'content_changed' => $contentChanged,
            'last_crawled_at' => $page->last_crawled_at,
            'last_changed_at' => $page->last_changed_at,
        ]);

        if (! $response->successful()) {
            $this->report($progress, 'page-parse-failed', [
                'source_page_id' => $page->id,
                'http_status' => $response->status(),
                'url' => $url,
            ]);

            throw SeasonvarSourceRequestException::forStatus($response->status());
        }

        return new SeasonvarFetchedPage(
            sourcePageId: $page->id,
            body: $body,
            contentHash: $contentHash,
            httpStatus: $response->status(),
            contentChanged: $contentChanged,
            snapshotId: $snapshot->id,
        );
    }

    /** @return array<string, string> */
    private function conditionalRequestHeaders(SourcePage $page): array
    {
        if ($page->parse_status !== 'parsed' || $page->content_hash === null) {
            return [];
        }

        return collect([
            'If-None-Match' => $page->etag,
            'If-Modified-Since' => $page->last_modified_header,
        ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    private function storeSnapshot(
        SourcePage $page,
        string $body,
        string $contentHash,
        int $httpStatus,
        ?int $importRunId,
    ): SourcePageSnapshot {
        $snapshotBody = $this->snapshotBody($page, $body);

        return SourcePageSnapshot::query()->updateOrCreate(
            [
                'source_page_id' => $page->id,
                'content_hash' => $contentHash,
            ],
            [
                'seasonvar_import_run_id' => $importRunId,
                'url' => $page->url,
                'http_status' => $httpStatus,
                'body_bytes' => mb_strlen($snapshotBody, '8bit'),
                'html' => $snapshotBody,
                'captured_at' => now(),
            ],
        );
    }

    private function snapshotBody(SourcePage $page, string $body): string
    {
        $pageType = SeasonvarPageType::tryFrom((string) $page->page_type) ?? SeasonvarPageType::Unknown;

        if ($pageType === SeasonvarPageType::Serial) {
            return $body;
        }

        return json_encode([
            'page_type' => $pageType->value,
            'source_body_bytes' => mb_strlen($body, '8bit'),
            'content_hash' => hash('sha256', $body),
            'snapshot_policy' => 'metadata_only_no_source_prose',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
