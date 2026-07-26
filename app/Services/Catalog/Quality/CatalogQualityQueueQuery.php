<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\DTOs\CatalogQuality\CatalogQualityIssueViewData;
use App\DTOs\CatalogQuality\CatalogQualityQueueItemData;
use App\DTOs\CatalogQuality\CatalogQualityQueueSummaryData;
use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogQualityQueueQuery
{
    /** @return list<string> */
    public static function queues(): array
    {
        return [
            'all',
            'critical',
            CatalogQualityIssueCategory::SuspiciousTags->value,
            CatalogQualityIssueCategory::DataConflicts->value,
            CatalogQualityIssueCategory::MissingPoster->value,
            CatalogQualityIssueCategory::MissingVideo->value,
            CatalogQualityIssueCategory::SuspiciousEpisodes->value,
            CatalogQualityIssueCategory::Stale->value,
        ];
    }

    /** @return list<string> */
    public static function sorts(): array
    {
        return ['score_asc', 'score_desc', 'stale', 'title'];
    }

    public function available(): bool
    {
        return Schema::hasTable('catalog_title_quality_snapshots')
            && Schema::hasTable('catalog_title_quality_issues');
    }

    /** @return list<CatalogQualityQueueSummaryData> */
    public function summary(): array
    {
        $snapshotCounts = DB::table('catalog_title_quality_snapshots')
            ->selectRaw('COUNT(*) AS aggregate')
            ->selectRaw(
                'SUM(CASE WHEN severity = ? THEN 1 ELSE 0 END) AS critical',
                [CatalogQualitySeverity::Critical->value],
            )
            ->first();
        $categoryCounts = CatalogTitleQualityIssue::query()
            ->select('category')
            ->selectRaw('COUNT(DISTINCT catalog_title_id) AS aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');
        $counts = [
            'all' => (int) $snapshotCounts->aggregate,
            'critical' => (int) $snapshotCounts->critical,
        ];

        foreach (CatalogQualityIssueCategory::cases() as $category) {
            $counts[$category->value] = (int) $categoryCounts->get($category->value, 0);
        }

        return array_map(
            static fn (string $queue): CatalogQualityQueueSummaryData => new CatalogQualityQueueSummaryData(
                code: $queue,
                label: __("catalog-quality.queues.{$queue}"),
                count: $counts[$queue] ?? 0,
            ),
            self::queues(),
        );
    }

    /**
     * @return LengthAwarePaginator<int, CatalogQualityQueueItemData>
     */
    public function paginate(
        string $queue,
        string $search,
        ?int $minimumScore,
        ?int $maximumScore,
        string $sort,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = CatalogTitleQualitySnapshot::query()
            ->join(
                'catalog_titles',
                'catalog_titles.id',
                '=',
                'catalog_title_quality_snapshots.catalog_title_id',
            )
            ->whereNull('catalog_titles.deleted_at')
            ->select('catalog_title_quality_snapshots.*')
            ->with([
                'catalogTitle:id,slug,title,original_title,year',
                'issues' => fn ($query) => $query
                    ->select([
                        'id',
                        'catalog_title_id',
                        'code',
                        'severity',
                        'penalty',
                        'evidence',
                    ])
                    ->orderByRaw(
                        "CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 WHEN 'notice' THEN 2 ELSE 3 END",
                    )
                    ->orderByDesc('penalty')
                    ->orderBy('code'),
            ]);

        if ($queue === 'critical') {
            $query->where(
                'catalog_title_quality_snapshots.severity',
                CatalogQualitySeverity::Critical->value,
            );
        } elseif ($queue !== 'all') {
            $query->whereExists(
                CatalogTitleQualityIssue::query()
                    ->selectRaw('1')
                    ->whereColumn(
                        'catalog_title_quality_issues.catalog_title_id',
                        'catalog_title_quality_snapshots.catalog_title_id',
                    )
                    ->where('category', $queue)
                    ->toBase(),
            );
        }

        if ($search !== '') {
            if (ctype_digit($search)) {
                $query->where('catalog_titles.id', (int) $search);
            } else {
                $prefix = $this->escapeLike($search).'%';
                $query->where(function (Builder $query) use ($prefix): void {
                    $query
                        ->where('catalog_titles.title', 'like', $prefix)
                        ->orWhere('catalog_titles.original_title', 'like', $prefix);
                });
            }
        }

        if ($minimumScore !== null) {
            $query->where(
                'catalog_title_quality_snapshots.quality_score',
                '>=',
                $minimumScore,
            );
        }

        if ($maximumScore !== null) {
            $query->where(
                'catalog_title_quality_snapshots.quality_score',
                '<=',
                $maximumScore,
            );
        }

        match ($sort) {
            'score_desc' => $query
                ->orderByDesc('catalog_title_quality_snapshots.quality_score')
                ->orderBy('catalog_title_quality_snapshots.catalog_title_id'),
            'stale' => $query
                ->orderByRaw(
                    'CASE WHEN catalog_title_quality_snapshots.last_source_checked_at IS NULL THEN 0 ELSE 1 END',
                )
                ->orderBy('catalog_title_quality_snapshots.last_source_checked_at')
                ->orderBy('catalog_title_quality_snapshots.catalog_title_id'),
            'title' => $query
                ->orderBy('catalog_titles.title')
                ->orderBy('catalog_title_quality_snapshots.catalog_title_id'),
            default => $query
                ->orderBy('catalog_title_quality_snapshots.quality_score')
                ->orderBy('catalog_title_quality_snapshots.catalog_title_id'),
        };

        return $query
            ->paginate(
                perPage: $perPage,
                pageName: 'qualityPage',
                page: $page,
            )
            ->through(fn (CatalogTitleQualitySnapshot $snapshot): CatalogQualityQueueItemData => $this->present($snapshot));
    }

    private function present(CatalogTitleQualitySnapshot $snapshot): CatalogQualityQueueItemData
    {
        $title = $snapshot->catalogTitle;
        abort_unless($title !== null, 404);

        return new CatalogQualityQueueItemData(
            catalogTitleId: (int) $snapshot->catalog_title_id,
            title: $title->display_title,
            originalTitle: $title->display_original_title,
            yearLabel: $title->year !== null
                ? (string) $title->year
                : __('catalog-quality.values.unknown'),
            score: $snapshot->quality_score,
            severity: $snapshot->severity->value,
            severityLabel: __("catalog-quality.severities.{$snapshot->severity->value}"),
            issueCount: $snapshot->issue_count,
            criticalCount: $snapshot->critical_count,
            sourceCheckedAtLabel: $this->dateLabel($snapshot->last_source_checked_at),
            evaluatedAtLabel: $this->dateLabel($snapshot->evaluated_at),
            needsRefresh: $snapshot->needs_refresh,
            issues: $snapshot->issues
                ->map(fn (CatalogTitleQualityIssue $issue): CatalogQualityIssueViewData => $this->presentIssue($issue))
                ->all(),
            editUrl: route('admin.catalog', ['catalog_q' => $snapshot->catalog_title_id]),
        );
    }

    private function presentIssue(CatalogTitleQualityIssue $issue): CatalogQualityIssueViewData
    {
        return new CatalogQualityIssueViewData(
            code: $issue->code,
            label: __("catalog-quality.issues.{$issue->code}"),
            detail: $this->evidenceLabel($issue),
            severity: $issue->severity->value,
            severityLabel: __("catalog-quality.severities.{$issue->severity->value}"),
        );
    }

    private function evidenceLabel(CatalogTitleQualityIssue $issue): string
    {
        $evidence = $issue->evidence;

        if ($issue->code === 'suspicious_tags') {
            $examples = implode(
                ', ',
                array_slice($this->stringValues($evidence['examples'] ?? null), 0, 8),
            );

            return $examples !== ''
                ? __('catalog-quality.evidence.examples', ['examples' => $examples])
                : '';
        }

        if (isset($evidence['age_days']) && is_numeric($evidence['age_days'])) {
            return trans_choice(
                'catalog-quality.evidence.age_days',
                (int) $evidence['age_days'],
                ['count' => (int) $evidence['age_days']],
            );
        }

        if ($issue->code === 'suspicious_episodes') {
            $numbers = implode(
                ', ',
                array_slice($this->integerValues($evidence['season_numbers'] ?? null), 0, 8),
            );

            return $numbers !== ''
                ? __('catalog-quality.evidence.seasons', ['seasons' => $numbers])
                : '';
        }

        if (isset($evidence['fields'])) {
            $fields = implode(
                ', ',
                array_map(
                    static fn (string $field): string => __("catalog-quality.fields.{$field}"),
                    array_slice($this->stringValues($evidence['fields']), 0, 8),
                ),
            );

            return $fields !== ''
                ? __('catalog-quality.evidence.fields', ['fields' => $fields])
                : '';
        }

        return '';
    }

    /** @return list<string> */
    private function stringValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @return list<int> */
    private function integerValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_int(...)));
    }

    private function dateLabel(?CarbonInterface $date): string
    {
        if ($date === null) {
            return __('catalog-quality.values.never');
        }

        return $date->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
