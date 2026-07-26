<?php

declare(strict_types=1);

namespace App\Services\Catalog\Quality;

use App\DTOs\CatalogQuality\CatalogTitleQualityFacts;
use App\DTOs\CatalogQuality\CatalogTitleQualityIssueData;
use App\DTOs\CatalogQuality\CatalogTitleQualityResult;
use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use App\Enums\TagType;
use Carbon\CarbonInterface;

final class CatalogTitleQualityEvaluator
{
    /** @var array<string, int|float> */
    private readonly array $settings;

    /** @param array<string, int|float>|null $settings */
    public function __construct(?array $settings = null)
    {
        if ($settings !== null) {
            $this->settings = $settings;

            return;
        }

        $configured = config('catalog-quality', []);
        $this->settings = is_array($configured) ? $configured : [];
    }

    public function evaluate(CatalogTitleQualityFacts $facts): CatalogTitleQualityResult
    {
        $issues = [
            ...$this->metadataIssues($facts),
            ...$this->tagIssues($facts),
            ...$this->providerConflictIssues($facts),
            ...$this->translationIssues($facts),
            ...$this->episodeIssues($facts),
            ...$this->freshnessIssues($facts),
            ...$this->mediaIssues($facts),
            ...$this->ratingIssues($facts),
            ...$this->seasonIssues($facts),
        ];

        $score = max(
            0,
            min(
                100,
                100 - array_sum(array_map(
                    static fn (CatalogTitleQualityIssueData $issue): int => $issue->penalty,
                    $issues,
                )),
            ),
        );
        $severity = CatalogQualitySeverity::Healthy;

        foreach ($issues as $issue) {
            if ($issue->severity->rank() > $severity->rank()) {
                $severity = $issue->severity;
            }
        }

        return new CatalogTitleQualityResult($score, $severity, $issues);
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function metadataIssues(CatalogTitleQualityFacts $facts): array
    {
        $issues = [];

        if (! $this->hasMeaningfulText($facts->title, 2)) {
            $issues[] = $this->issue(
                'invalid_title',
                CatalogQualityIssueCategory::CriticalErrors,
                CatalogQualitySeverity::Critical,
                20,
                ['field' => 'title'],
            );
        }

        if ($facts->year === null || $facts->year < 1888 || $facts->year > ((int) $facts->evaluatedAt->format('Y')) + 3) {
            $issues[] = $this->issue(
                'missing_year',
                CatalogQualityIssueCategory::CriticalErrors,
                CatalogQualitySeverity::Warning,
                8,
                ['field' => 'year'],
            );
        }

        if ($facts->countries === []) {
            $issues[] = $this->issue(
                'missing_country',
                CatalogQualityIssueCategory::CriticalErrors,
                CatalogQualitySeverity::Warning,
                6,
                ['field' => 'countries'],
            );
        }

        if ($facts->genres === []) {
            $issues[] = $this->issue(
                'missing_genres',
                CatalogQualityIssueCategory::CriticalErrors,
                CatalogQualitySeverity::Critical,
                12,
                ['field' => 'genres'],
            );
        }

        if (! $this->hasMeaningfulText($facts->posterUrl, 8)) {
            $issues[] = $this->issue(
                'missing_poster',
                CatalogQualityIssueCategory::MissingPoster,
                CatalogQualitySeverity::Warning,
                10,
                ['field' => 'poster_url'],
            );
        }

        if (! $this->hasMeaningfulText($facts->description, 30)) {
            $issues[] = $this->issue(
                'missing_description',
                CatalogQualityIssueCategory::CriticalErrors,
                CatalogQualitySeverity::Warning,
                8,
                ['field' => 'description'],
            );
        }

        return $issues;
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function tagIssues(CatalogTitleQualityFacts $facts): array
    {
        $context = $this->normalize(implode(' ', array_filter([
            $facts->title,
            $facts->originalTitle,
            $facts->description,
            ...$facts->genres,
        ])));
        $suspicious = [];

        foreach ($facts->tags as $tag) {
            if ($tag['type'] !== TagType::Imported->value || $tag['has_current_provenance']) {
                continue;
            }

            $normalizedTag = $this->normalize($tag['name']);

            if ($normalizedTag !== '' && str_contains(" {$context} ", " {$normalizedTag} ")) {
                continue;
            }

            $suspicious[] = $this->boundedText($tag['name']);
        }

        if ($suspicious === []) {
            return [];
        }

        sort($suspicious, SORT_NATURAL | SORT_FLAG_CASE);

        return [$this->issue(
            'suspicious_tags',
            CatalogQualityIssueCategory::SuspiciousTags,
            CatalogQualitySeverity::Warning,
            min(20, 4 + count($suspicious) * 2),
            [
                'count' => count($suspicious),
                'examples' => array_slice($suspicious, 0, 8),
            ],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function providerConflictIssues(CatalogTitleQualityFacts $facts): array
    {
        $current = [
            'title' => $facts->title,
            'original_title' => $facts->originalTitle,
            'description' => $facts->description,
            'poster_url' => $facts->posterUrl,
        ];
        $conflicts = [];

        foreach ($current as $field => $value) {
            if (! array_key_exists($field, $facts->providerFieldValues)) {
                continue;
            }

            $providerValue = $facts->providerFieldValues[$field];

            if (! is_scalar($providerValue) && $providerValue !== null) {
                continue;
            }

            if ($this->comparable($value) !== $this->comparable($providerValue)) {
                $conflicts[] = $field;
            }
        }

        if ($conflicts === []) {
            return [];
        }

        sort($conflicts);

        return [$this->issue(
            'data_conflicts',
            CatalogQualityIssueCategory::DataConflicts,
            CatalogQualitySeverity::Notice,
            min(12, count($conflicts) * 3),
            ['fields' => $conflicts],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function translationIssues(CatalogTitleQualityFacts $facts): array
    {
        $suspiciousFields = [];

        foreach ([
            'title' => $facts->title,
            'original_title' => $facts->originalTitle,
        ] as $field => $value) {
            if ($value !== null && preg_match('/(?:�|&#|<[^>]+>|\?{3,})/u', $value) === 1) {
                $suspiciousFields[] = $field;
            }
        }

        if ($suspiciousFields === []) {
            return [];
        }

        return [$this->issue(
            'strange_translation',
            CatalogQualityIssueCategory::DataConflicts,
            CatalogQualitySeverity::Warning,
            8,
            ['fields' => $suspiciousFields],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function episodeIssues(CatalogTitleQualityFacts $facts): array
    {
        $anomalousSeasons = [];

        foreach ($facts->seasons as $season) {
            $count = $season['regular_episode_count'];
            $minimum = $season['minimum_episode_number'];
            $maximum = $season['maximum_episode_number'];
            $span = $minimum !== null && $maximum !== null
                ? max(0, $maximum - $minimum + 1)
                : $count;
            $gaps = max(0, $span - $season['distinct_episode_number_count']);
            $gapThreshold = max(
                (int) $this->setting('episode_gap_minimum', 3),
                (int) ceil(max(1, $span) * $this->setting('episode_gap_ratio', 0.10)),
            );
            $invalidNumbers = $count > 0 && ($minimum === null || $minimum < 1);
            $duplicates = $count !== $season['distinct_episode_number_count'];
            $released = $season['released_episode_count'];
            $total = $season['total_episode_count'];
            $countConflict = ($released !== null && $released < 0)
                || ($total !== null && $total < 0)
                || ($released !== null && $total !== null && $released > $total)
                || ($released !== null && $released > 0 && $count === 0);

            if (! $invalidNumbers && ! $duplicates && $gaps < $gapThreshold && ! $countConflict) {
                continue;
            }

            $anomalousSeasons[] = $season['number'];
        }

        if ($anomalousSeasons === []) {
            return [];
        }

        sort($anomalousSeasons);

        return [$this->issue(
            'suspicious_episodes',
            CatalogQualityIssueCategory::SuspiciousEpisodes,
            CatalogQualitySeverity::Warning,
            min(20, 6 + count($anomalousSeasons) * 2),
            [
                'count' => count($anomalousSeasons),
                'season_numbers' => array_slice($anomalousSeasons, 0, 8),
            ],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function freshnessIssues(CatalogTitleQualityFacts $facts): array
    {
        if ($facts->lastSourceCheckedAt === null) {
            return [$this->issue(
                'source_never_checked',
                CatalogQualityIssueCategory::Stale,
                CatalogQualitySeverity::Critical,
                15,
                ['field' => 'last_source_checked_at'],
            )];
        }

        $age = $this->ageInDays($facts->lastSourceCheckedAt, $facts->evaluatedAt);

        if ($age <= $this->setting('stale_source_after_days', 30)) {
            return [];
        }

        return [$this->issue(
            'stale_source',
            CatalogQualityIssueCategory::Stale,
            CatalogQualitySeverity::Warning,
            8,
            ['age_days' => $age],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function mediaIssues(CatalogTitleQualityFacts $facts): array
    {
        if ($facts->media['published_playable_count'] === 0) {
            return [$this->issue(
                'missing_video',
                CatalogQualityIssueCategory::MissingVideo,
                CatalogQualitySeverity::Critical,
                20,
                ['published_playable_count' => 0],
            )];
        }

        if ($facts->media['never_checked_count'] > 0) {
            return [$this->issue(
                'media_never_checked',
                CatalogQualityIssueCategory::Stale,
                CatalogQualitySeverity::Warning,
                6,
                ['count' => $facts->media['never_checked_count']],
            )];
        }

        $latestCheckedAt = $facts->media['latest_checked_at'];

        if ($latestCheckedAt === null) {
            return [];
        }

        $age = $this->ageInDays($latestCheckedAt, $facts->evaluatedAt);

        if ($age <= $this->setting('stale_media_after_days', 14)) {
            return [];
        }

        return [$this->issue(
            'stale_video_check',
            CatalogQualityIssueCategory::Stale,
            CatalogQualitySeverity::Notice,
            4,
            ['age_days' => $age],
        )];
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function ratingIssues(CatalogTitleQualityFacts $facts): array
    {
        $invalidProviders = [];
        $valid = [];

        foreach ($facts->ratings as $rating) {
            if ($rating['rating'] === null) {
                continue;
            }

            if ($rating['rating'] < 0 || $rating['rating'] > 10 || ($rating['votes'] !== null && $rating['votes'] < 0)) {
                $invalidProviders[] = $this->boundedText($rating['provider']);

                continue;
            }

            $valid[] = $rating['rating'];
        }

        $issues = [];

        if ($invalidProviders !== []) {
            sort($invalidProviders);
            $issues[] = $this->issue(
                'invalid_ratings',
                CatalogQualityIssueCategory::DataConflicts,
                CatalogQualitySeverity::Critical,
                12,
                ['providers' => array_slice($invalidProviders, 0, 8)],
            );
        }

        if (count($valid) >= 2 && max($valid) - min($valid) >= $this->setting('rating_disagreement_threshold', 3.0)) {
            $issues[] = $this->issue(
                'rating_conflict',
                CatalogQualityIssueCategory::DataConflicts,
                CatalogQualitySeverity::Notice,
                5,
                ['spread' => round(max($valid) - min($valid), 2)],
            );
        }

        return $issues;
    }

    /** @return list<CatalogTitleQualityIssueData> */
    private function seasonIssues(CatalogTitleQualityFacts $facts): array
    {
        $seasonNumbers = array_column($facts->seasons, 'number');
        $count = count($seasonNumbers);
        $maximum = $seasonNumbers === [] ? null : max($seasonNumbers);

        if ($count <= $this->setting('season_count_warning', 50)
            && ($maximum === null || $maximum <= $this->setting('season_number_warning', 100))) {
            return [];
        }

        return [$this->issue(
            'suspicious_season_count',
            CatalogQualityIssueCategory::DataConflicts,
            CatalogQualitySeverity::Warning,
            8,
            [
                'regular_season_count' => $count,
                'maximum_season_number' => $maximum,
            ],
        )];
    }

    /**
     * @param  array<string, bool|float|int|string|list<int|string>|null>  $evidence
     */
    private function issue(
        string $code,
        CatalogQualityIssueCategory $category,
        CatalogQualitySeverity $severity,
        int $penalty,
        array $evidence,
    ): CatalogTitleQualityIssueData {
        return new CatalogTitleQualityIssueData($code, $category, $severity, $penalty, $evidence);
    }

    private function hasMeaningfulText(?string $value, int $minimumLength): bool
    {
        if ($value === null || mb_strlen(trim($value)) < $minimumLength) {
            return false;
        }

        return preg_match('/[\pL\pN]/u', $value) === 1;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function comparable(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $this->normalize(trim((string) $value));
    }

    private function boundedText(string $value): string
    {
        return mb_substr(trim($value), 0, 80);
    }

    private function ageInDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, (int) floor($from->diffInSeconds($to, false) / 86400));
    }

    private function setting(string $key, int|float $default): int|float
    {
        $value = $this->settings[$key] ?? $default;

        return is_int($default) ? (int) $value : (float) $value;
    }
}
