<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarSourceInventoryResult;
use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use Illuminate\Support\Collection;
use Throwable;

final class SeasonvarSourceInventory
{
    private const SAMPLE_LIMIT_PER_TYPE = 3;

    public function __construct(
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarUrl $urls,
        private readonly SeasonvarSourceParityRegistry $parity,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly SeasonvarImportStorageMaintenance $storageMaintenance,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function run(
        ?int $processId = null,
        ?string $processHost = null,
        ?string $processCommand = null,
        ?callable $progress = null,
    ): SeasonvarSourceInventoryResult {
        $startedAt = now();
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'inventory',
            'execution_mode' => 'sync',
            'status' => 'running',
            'force' => false,
            'forever' => false,
            'process_id' => $processId,
            'process_host' => $processHost,
            'process_command' => $processCommand,
            'cycles' => 0,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $startedAt,
        ]);

        $this->recordEvent($run, 'seasonvar-source-inventory-started', [
            'mode' => 'inventory',
        ]);
        $this->report($progress, 'seasonvar-source-inventory-started', [
            'import_run_id' => $run->id,
        ]);

        try {
            $mirror = $this->sitemapMirror->mirror($progress);
            $inventoryUrls = collect($mirror['archives'])
                ->pluck('url')
                ->merge($mirror['urls'])
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->unique()
                ->values();
            $counts = $this->countsByType($inventoryUrls);
            $samples = $this->samplesByType($inventoryUrls);
            $stored = $this->importer->storeDiscoveredUrls($inventoryUrls->all(), $progress);
            $unsupported = $this->parity->unsupportedDiscoveredTypes($counts);
            $warnings = $this->warnings(
                $mirror['warnings'],
                (int) $mirror['duplicate_url_count'],
                $unsupported,
            );
            $completedAt = now();
            $result = new SeasonvarSourceInventoryResult(
                importRunId: (int) $run->id,
                startedAt: $startedAt,
                completedAt: $completedAt,
                sitemapCount: (int) $mirror['archive_count'],
                totalUrlCount: $inventoryUrls->count(),
                storedUrlCount: $stored,
                countsByPageType: $counts,
                unknownUrlCount: (int) ($counts[SeasonvarPageType::Unknown->value] ?? 0),
                malformedUrlCount: (int) $mirror['malformed_url_count'],
                blockedUrlCount: (int) $mirror['blocked_url_count'],
                duplicateUrlCount: (int) $mirror['duplicate_url_count'],
                sampleUrlsByPageType: $samples,
                locallySupportedImportTypes: $this->parity->supportedImportTypes(),
                locallySupportedPublicPageTypes: $this->parity->supportedPublicPageTypes(),
                discoveredButUnsupportedTypes: $unsupported,
                warnings: $warnings,
                failureDetails: [],
            );

            $run->fill([
                'status' => 'completed',
                'cycles' => 1,
                'discovered' => $result->totalUrlCount,
                'stored' => $result->storedUrlCount,
                'summary' => ['source_inventory' => $result->toArray()],
                'last_heartbeat_at' => $completedAt,
                'finished_at' => $completedAt,
            ])->save();

            $this->recordEvent($run, 'seasonvar-source-inventory-complete', $this->eventSummary($result));
            $this->report($progress, 'seasonvar-source-inventory-complete', $this->eventSummary($result));

            return $result;
        } catch (Throwable $exception) {
            $completedAt = now();
            $failure = $this->errors->fromException($exception);
            $result = new SeasonvarSourceInventoryResult(
                importRunId: (int) $run->id,
                startedAt: $startedAt,
                completedAt: $completedAt,
                sitemapCount: 0,
                totalUrlCount: 0,
                storedUrlCount: 0,
                countsByPageType: [],
                unknownUrlCount: 0,
                malformedUrlCount: 0,
                blockedUrlCount: 0,
                duplicateUrlCount: 0,
                sampleUrlsByPageType: [],
                locallySupportedImportTypes: $this->parity->supportedImportTypes(),
                locallySupportedPublicPageTypes: $this->parity->supportedPublicPageTypes(),
                discoveredButUnsupportedTypes: [],
                warnings: ['Инвентаризация остановлена до получения полного снимка карты сайта.'],
                failureDetails: [$failure],
            );

            $run->fill([
                'status' => 'failed',
                'cycles' => 1,
                'summary' => ['source_inventory' => $result->toArray()],
                'last_error' => $failure,
                'last_heartbeat_at' => $completedAt,
                'finished_at' => $completedAt,
            ])->save();

            $this->recordEvent($run, 'seasonvar-source-inventory-failed', [
                'failure' => $failure,
                'exception' => $exception::class,
            ]);
            $this->report($progress, 'seasonvar-source-inventory-failed', [
                'failure' => $failure,
            ]);

            return $result;
        }
    }

    /**
     * @template TUrl of string
     *
     * @param  Collection<int, TUrl>  $urls
     * @return array<string, int>
     */
    private function countsByType(Collection $urls): array
    {
        $counts = [];

        foreach ($urls as $url) {
            $type = $this->urls->pageType($url)->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $this->sortByEnumOrder($counts);
    }

    /**
     * @template TUrl of string
     *
     * @param  Collection<int, TUrl>  $urls
     * @return array<string, list<string>>
     */
    private function samplesByType(Collection $urls): array
    {
        $samples = [];

        foreach ($urls as $url) {
            $type = $this->urls->pageType($url)->value;
            $samples[$type] ??= [];

            if (count($samples[$type]) >= self::SAMPLE_LIMIT_PER_TYPE) {
                continue;
            }

            $path = $this->urls->sanitizedPath($url);

            if (! in_array($path, $samples[$type], true)) {
                $samples[$type][] = $path;
            }
        }

        return $this->sortByEnumOrder($samples);
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $values
     * @return array<string, TValue>
     */
    private function sortByEnumOrder(array $values): array
    {
        $sorted = [];

        foreach (SeasonvarPageType::cases() as $type) {
            if (array_key_exists($type->value, $values)) {
                $sorted[$type->value] = $values[$type->value];
            }
        }

        return $sorted;
    }

    /**
     * @param  list<string>  $mirrorWarnings
     * @param  list<string>  $unsupported
     * @return list<string>
     */
    private function warnings(array $mirrorWarnings, int $duplicates, array $unsupported): array
    {
        $warnings = $mirrorWarnings;

        if ($duplicates > 0) {
            $warnings[] = "Повторяющиеся URL нормализованы и учтены один раз: {$duplicates}.";
        }

        if ($unsupported !== []) {
            $warnings[] = 'Обнаружены типы без полного локального parser/public-page parity: '.implode(', ', $unsupported).'.';
        }

        return array_values(array_unique($warnings));
    }

    /** @return array<string, int|array<string, int>> */
    private function eventSummary(SeasonvarSourceInventoryResult $result): array
    {
        return [
            'sitemap_count' => $result->sitemapCount,
            'total_url_count' => $result->totalUrlCount,
            'stored_url_count' => $result->storedUrlCount,
            'unknown_url_count' => $result->unknownUrlCount,
            'malformed_url_count' => $result->malformedUrlCount,
            'blocked_url_count' => $result->blockedUrlCount,
            'counts_by_page_type' => $result->countsByPageType,
        ];
    }

    /** @param array<string, mixed> $context */
    private function recordEvent(SeasonvarImportRun $run, string $event, array $context): void
    {
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'event' => $event,
            'level' => str_contains($event, 'failed') ? 'warning' : 'info',
            'context' => $this->storageMaintenance->sanitizeEventContext($context),
        ]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
