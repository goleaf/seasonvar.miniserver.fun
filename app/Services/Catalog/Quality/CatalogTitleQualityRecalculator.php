<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\DTOs\CatalogQuality\CatalogTitleQualityIssueData;
use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogQualityRun;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CatalogTitleQualityRecalculator
{
    public function __construct(
        private CatalogTitleQualityInputLoader $loader,
        private CatalogTitleQualityEvaluator $evaluator,
    ) {}

    /** @param iterable<int, int|string> $titleIds */
    public function recalculate(iterable $titleIds, ?CatalogQualityRun $run = null): int
    {
        $evaluatedAt = CarbonImmutable::now();
        $facts = $this->loader->load($titleIds, $evaluatedAt);

        foreach ($facts as $fact) {
            $result = $this->evaluator->evaluate($fact);

            DB::transaction(function () use ($fact, $result, $evaluatedAt, $run): void {
                $firstDetectedAt = CatalogTitleQualityIssue::query()
                    ->where('catalog_title_id', $fact->catalogTitleId)
                    ->pluck('first_detected_at', 'code');
                $codes = $result->issueCodes();
                $rows = array_map(
                    static fn (CatalogTitleQualityIssueData $issue): array => [
                        'catalog_title_id' => $fact->catalogTitleId,
                        'catalog_quality_run_id' => $run?->id,
                        'code' => $issue->code,
                        'category' => $issue->category->value,
                        'severity' => $issue->severity->value,
                        'penalty' => $issue->penalty,
                        'evidence' => json_encode(
                            $issue->evidence,
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                        'first_detected_at' => $firstDetectedAt->get($issue->code, $evaluatedAt),
                        'last_detected_at' => $evaluatedAt,
                        'created_at' => $evaluatedAt,
                        'updated_at' => $evaluatedAt,
                    ],
                    $result->issues,
                );

                if ($rows !== []) {
                    CatalogTitleQualityIssue::query()->upsert(
                        $rows,
                        ['catalog_title_id', 'code'],
                        [
                            'category',
                            'catalog_quality_run_id',
                            'severity',
                            'penalty',
                            'evidence',
                            'last_detected_at',
                            'updated_at',
                        ],
                    );
                }

                $resolved = CatalogTitleQualityIssue::query()
                    ->where('catalog_title_id', $fact->catalogTitleId);

                if ($codes !== []) {
                    $resolved->whereNotIn('code', $codes);
                }

                $resolved->delete();

                CatalogTitleQualitySnapshot::query()->updateOrCreate(
                    ['catalog_title_id' => $fact->catalogTitleId],
                    [
                        'quality_score' => $result->score,
                        'catalog_quality_run_id' => $run?->id,
                        'severity' => $result->severity,
                        'issue_count' => count($result->issues),
                        'critical_count' => collect($result->issues)->filter(
                            static fn (CatalogTitleQualityIssueData $issue): bool => $issue->severity === CatalogQualitySeverity::Critical,
                        )->count(),
                        'needs_refresh' => false,
                        'scoring_version' => (int) config('catalog-quality.scoring_version', 1),
                        'last_source_checked_at' => $fact->lastSourceCheckedAt,
                        'evaluated_at' => $evaluatedAt,
                    ],
                );
            }, attempts: 3);
        }

        return $facts->count();
    }
}
