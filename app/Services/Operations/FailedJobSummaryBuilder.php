<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FailedJobSummaryBuilder
{
    public function __construct(
        private readonly FailedJobMetadataClassifier $classifier,
    ) {}

    /**
     * @return array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>, reasons: array<string, int>}
     */
    public function build(): array
    {
        if (! Schema::hasTable((string) config('queue.failed.table', 'failed_jobs'))) {
            return $this->emptySummary();
        }

        $jobs = [];
        $categories = [];
        $ages = [];
        $reasons = [];
        $total = 0;
        $now = CarbonImmutable::now();

        DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->select(['id', 'queue', 'failed_at'])
            ->selectRaw("CASE WHEN json_valid(payload) THEN json_extract(payload, '$.displayName') END AS display_name")
            ->selectRaw('substr(exception, 1, ?) AS exception_prefix', [FailedJobMetadataClassifier::EXCEPTION_PREFIX_BYTES])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$jobs, &$categories, &$ages, &$reasons, &$total, $now): void {
                foreach ($rows as $row) {
                    $total++;
                    $this->increment($jobs, $this->classifier->jobLabel(
                        is_string($row->display_name) ? $row->display_name : null,
                    ));
                    $this->increment($categories, $this->categoryLabel((string) $row->queue));
                    $this->increment($ages, $this->classifier->ageLabel((string) $row->failed_at, $now));
                    $this->increment($reasons, $this->classifier->reasonLabel(
                        is_string($row->exception_prefix) ? $row->exception_prefix : null,
                    ));
                }
            }, 'id');

        ksort($jobs);
        ksort($categories);
        ksort($ages);
        ksort($reasons);

        return compact('total', 'jobs', 'categories', 'ages', 'reasons');
    }

    /** @return array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>, reasons: array<string, int>} */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'jobs' => [],
            'categories' => [],
            'ages' => [],
            'reasons' => [],
        ];
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

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $label): void
    {
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }
}
