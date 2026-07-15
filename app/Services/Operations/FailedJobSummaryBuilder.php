<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FailedJobSummaryBuilder
{
    private const JOB_LABELS = [
        'App\\Jobs\\ProcessSeasonvarImportPage' => 'import_page',
        'App\\Jobs\\FinalizeSeasonvarQueuedImport' => 'finalize_import',
        'App\\Jobs\\RefreshSeasonvarCatalogTitle' => 'refresh_catalog_title',
        'App\\Jobs\\RunSeasonvarImport' => 'run_import',
        'App\\Jobs\\StartSeasonvarQueuedImport' => 'start_import',
        'App\\Jobs\\WarmCatalogCaches' => 'warm_catalog_cache',
    ];

    /**
     * @return array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>}
     */
    public function build(): array
    {
        if (! Schema::hasTable((string) config('queue.failed.table', 'failed_jobs'))) {
            return $this->emptySummary();
        }

        $jobs = [];
        $categories = [];
        $ages = [];
        $total = 0;
        $now = CarbonImmutable::now();

        DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->select(['id', 'queue', 'payload', 'failed_at'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$jobs, &$categories, &$ages, &$total, $now): void {
                foreach ($rows as $row) {
                    $total++;
                    $this->increment($jobs, $this->jobLabel((string) $row->payload));
                    $this->increment($categories, $this->categoryLabel((string) $row->queue));
                    $this->increment($ages, $this->ageLabel((string) $row->failed_at, $now));
                }
            }, 'id');

        ksort($jobs);
        ksort($categories);
        ksort($ages);

        return compact('total', 'jobs', 'categories', 'ages');
    }

    /** @return array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>} */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'jobs' => [],
            'categories' => [],
            'ages' => [],
        ];
    }

    private function jobLabel(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $displayName = is_array($decoded) && is_string($decoded['displayName'] ?? null)
            ? $decoded['displayName']
            : null;

        return $displayName !== null
            ? (self::JOB_LABELS[$displayName] ?? 'other')
            : 'other';
    }

    private function categoryLabel(string $queue): string
    {
        return match ($queue) {
            'seasonvar-import' => 'import',
            'seasonvar-title-refresh' => 'title_refresh',
            'cache-warm', 'cache-warm-v2' => 'cache',
            'critical' => 'critical',
            'default' => 'default',
            default => 'other',
        };
    }

    private function ageLabel(string $failedAt, CarbonImmutable $now): string
    {
        $hours = CarbonImmutable::parse($failedAt)->diffInHours($now);

        return match (true) {
            $hours < 1 => 'under_1_hour',
            $hours < 24 => '1_to_24_hours',
            $hours < 168 => '1_to_7_days',
            default => 'over_7_days',
        };
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $label): void
    {
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }
}
