<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationQualityReport;

final class CatalogRecommendationQualityEvaluator
{
    /**
     * @param  iterable<array-key, mixed>  $rows
     * @param  array<string, array<string, int|float>>  $grades
     */
    public function evaluate(iterable $rows, array $grades, int $limit = 12): CatalogRecommendationQualityReport
    {
        $limit = max(1, min(100, $limit));
        $normalizedRows = $this->normalizeRows($rows);
        $normalizedGrades = $this->normalizeGrades($grades);
        $sourceNames = array_values(array_unique([
            ...array_keys($normalizedGrades),
            ...array_column($normalizedRows, 'source'),
        ]));
        sort($sourceNames, SORT_STRING);
        $rowsBySource = [];
        $incoming = [];
        $watchableRows = 0;
        $reasonFaithfulnessFailures = 0;

        foreach ($normalizedRows as $row) {
            $rowsBySource[$row['source']][] = $row;
            $incoming[$row['candidate']] = ($incoming[$row['candidate']] ?? 0) + 1;
            $watchableRows += $row['watchable'] ? 1 : 0;
            $reasonFaithfulnessFailures += $row['reasons'] === [] ? 1 : 0;
        }

        $precision = 0.0;
        $precisionSources = 0;
        $ndcg = 0.0;
        $ndcgSources = 0;
        $emptySourceCount = 0;
        $judgedRowCount = 0;
        $positiveJudgmentCount = 0;
        $retrievedPositiveJudgmentCount = 0;

        foreach ($sourceNames as $source) {
            $sourceRows = $rowsBySource[$source] ?? [];
            usort($sourceRows, fn (array $left, array $right): int => ($left['rank'] <=> $right['rank'])
                ?: strcmp($left['candidate'], $right['candidate']));
            $sourceRows = array_slice($sourceRows, 0, $limit);

            if ($sourceRows === []) {
                $emptySourceCount++;
            }

            $sourceGrades = $normalizedGrades[$source] ?? [];
            $positiveJudgmentCount += count(array_filter(
                $sourceGrades,
                static fn (int $grade): bool => $grade > 0,
            ));
            $judgedRows = array_values(array_filter(
                $sourceRows,
                fn (array $row): bool => array_key_exists($row['candidate'], $sourceGrades),
            ));
            $judgedRowCount += count($judgedRows);
            $relevant = count(array_filter(
                $judgedRows,
                fn (array $row): bool => $sourceGrades[$row['candidate']] > 0,
            ));
            $retrievedPositiveJudgmentCount += $relevant;

            if ($judgedRows !== []) {
                $precision += $relevant / count($judgedRows);
                $precisionSources++;
            }

            $idealGrades = array_values($sourceGrades);
            rsort($idealGrades, SORT_NUMERIC);
            $idealDcg = $this->discountedCumulativeGain(array_slice($idealGrades, 0, $limit));

            if ($idealDcg <= 0.0) {
                continue;
            }

            $rankedGrades = array_map(
                fn (array $row): int => $sourceGrades[$row['candidate']],
                $judgedRows,
            );
            $ndcg += $this->discountedCumulativeGain($rankedGrades) / $idealDcg;
            $ndcgSources++;
        }

        $sourceCount = count($sourceNames);
        $rowCount = count($normalizedRows);

        return new CatalogRecommendationQualityReport(
            precisionAtLimit: round($precisionSources > 0 ? $precision / $precisionSources : 0.0, 4),
            ndcgAtLimit: round($ndcgSources > 0 ? $ndcg / $ndcgSources : 0.0, 4),
            sourceCount: $sourceCount,
            emptySourceCount: $emptySourceCount,
            watchableRate: round($rowCount > 0 ? $watchableRows / $rowCount : 0.0, 4),
            candidateCoverage: count($incoming),
            maximumIncoming: $incoming === [] ? 0 : max($incoming),
            incomingAtLeast100: count(array_filter($incoming, fn (int $count): bool => $count >= 100)),
            reasonFaithfulnessFailures: $reasonFaithfulnessFailures,
            judgedRowCount: $judgedRowCount,
            judgmentCoverage: round(
                $positiveJudgmentCount > 0
                    ? $retrievedPositiveJudgmentCount / $positiveJudgmentCount
                    : 0.0,
                4,
            ),
        );
    }

    /**
     * @param  iterable<array-key, mixed>  $rows
     * @return list<array{source: string, candidate: string, rank: int, watchable: bool, reasons: list<string>}>
     */
    private function normalizeRows(iterable $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $source = is_string($row['source'] ?? null) ? trim($row['source']) : '';
            $candidate = is_string($row['candidate'] ?? null) ? trim($row['candidate']) : '';
            $rank = is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : 0;

            if ($source === '' || $candidate === '' || $rank < 1) {
                continue;
            }

            $reasons = is_array($row['reasons'] ?? null)
                ? array_values(array_unique(array_map(
                    fn (string $reason): string => trim($reason),
                    array_filter($row['reasons'], fn (mixed $reason): bool => is_string($reason) && trim($reason) !== ''),
                )))
                : [];
            sort($reasons, SORT_STRING);
            $key = $source."\0".$candidate;
            $candidateRow = [
                'source' => $source,
                'candidate' => $candidate,
                'rank' => $rank,
                'watchable' => (bool) ($row['watchable'] ?? false),
                'reasons' => $reasons,
            ];
            $current = $normalized[$key] ?? null;

            if ($current === null) {
                $normalized[$key] = $candidateRow;

                continue;
            }

            $current['rank'] = min($current['rank'], $candidateRow['rank']);
            $current['watchable'] = $current['watchable'] || $candidateRow['watchable'];
            $current['reasons'] = array_values(array_unique([
                ...$current['reasons'],
                ...$candidateRow['reasons'],
            ]));
            sort($current['reasons'], SORT_STRING);
            $normalized[$key] = $current;
        }

        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /**
     * @param  array<string, array<string, int|float>>  $grades
     * @return array<string, array<string, int>>
     */
    private function normalizeGrades(array $grades): array
    {
        $normalized = [];

        foreach ($grades as $source => $sourceGrades) {
            if (! is_string($source)) {
                continue;
            }

            $source = trim($source);

            if ($source === '' || ! is_array($sourceGrades)) {
                continue;
            }

            foreach ($sourceGrades as $candidate => $grade) {
                if (! is_string($candidate)) {
                    continue;
                }

                $candidate = trim($candidate);

                if ($candidate === '' || ! is_numeric($grade)) {
                    continue;
                }

                $normalized[$source][$candidate] = max(0, min(2, (int) $grade));
            }

            $normalized[$source] ??= [];
            ksort($normalized[$source], SORT_STRING);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param list<int> $grades */
    private function discountedCumulativeGain(array $grades): float
    {
        $score = 0.0;

        foreach ($grades as $index => $grade) {
            $gain = (2 ** $grade) - 1;
            $score += $gain / log($index + 2, 2);
        }

        return $score;
    }
}
