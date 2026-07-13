<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Models\SourcePage;
use Illuminate\Support\Collection;

final readonly class SeasonvarDiscoveredPageStore
{
    public function __construct(
        private SeasonvarSource $source,
        private SeasonvarUrl $urls,
    ) {}

    /**
     * @param  list<string>  $urls
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function store(array $urls, string $discoveredFromUrl, ?int $deferMinutes = null, ?callable $progress = null): int
    {
        $source = $this->source->current();
        $normalized = collect($urls)->unique()->values();
        $stored = 0;
        $processed = 0;
        $updated = 0;

        $normalized->chunk(500)->each(function (Collection $chunk) use ($source, $normalized, $discoveredFromUrl, $deferMinutes, &$stored, &$processed, &$updated, $progress): void {
            $now = now();
            $rows = $chunk->mapWithKeys(function (string $url) use ($source, $discoveredFromUrl, $deferMinutes, $now): array {
                $hash = $this->urls->hash($url);

                return [$hash => [
                    'source_id' => $source->id,
                    'url' => $url,
                    'url_hash' => $hash,
                    'page_type' => $this->urls->pageType($url)->value,
                    'parse_status' => 'pending',
                    'import_status' => 'pending',
                    'retry_after_at' => $deferMinutes !== null ? $now->copy()->addMinutes(max(1, $deferMinutes)) : null,
                    'discovered_from_url' => $discoveredFromUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });
            $existing = SourcePage::query()
                ->whereIn('url_hash', $rows->keys())
                ->get(['url_hash', 'source_id', 'url', 'page_type', 'discovered_from_url'])
                ->keyBy('url_hash');
            $newHashes = $rows->keys()->diff($existing->keys());
            $stored += $newHashes->count();
            $processed += $rows->count();
            $changed = $rows->filter(function (array $row, string $hash) use ($existing): bool {
                $page = $existing->get($hash);

                return $page === null
                    || (int) $page->source_id !== (int) $row['source_id']
                    || $page->url !== $row['url']
                    || $page->page_type !== $row['page_type']
                    || $page->discovered_from_url !== $row['discovered_from_url'];
            });
            $updated += $changed->count() - $newHashes->count();

            if ($changed->isNotEmpty()) {
                SourcePage::query()->upsert(
                    $changed->values()->all(),
                    ['url_hash'],
                    ['source_id', 'url', 'page_type', 'discovered_from_url', 'updated_at'],
                );
            }

            if ($progress !== null) {
                $progress('store-discovered-urls-chunk-complete', [
                    'processed' => $processed,
                    'total' => $normalized->count(),
                    'stored' => $stored,
                    'updated' => $updated,
                    'unchanged' => $processed - $stored - $updated,
                ]);
            }
        });

        return $stored;
    }
}
