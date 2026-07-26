<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogQuality\CatalogTitleQualityFacts;
use App\Enums\CatalogQualityIssueCategory;
use App\Services\Catalog\Quality\CatalogTitleQualityEvaluator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogTitleQualityEvaluatorTest extends TestCase
{
    #[Test]
    public function complete_consistent_title_has_maximum_score_without_issues(): void
    {
        $result = $this->evaluator()->evaluate($this->healthyFacts());

        self::assertSame(100, $result->score);
        self::assertSame([], $result->issues);
        self::assertSame('healthy', $result->severity->value);
    }

    #[Test]
    public function missing_required_metadata_and_video_are_explained_and_score_is_clamped(): void
    {
        $facts = CatalogTitleQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'title' => '---',
            'year' => null,
            'description' => null,
            'poster_url' => null,
            'countries' => [],
            'genres' => [],
            'media' => [
                'published_playable_count' => 0,
                'never_checked_count' => 0,
                'latest_checked_at' => null,
            ],
            'last_source_checked_at' => null,
        ]);

        $result = $this->evaluator()->evaluate($facts);

        self::assertGreaterThanOrEqual(0, $result->score);
        self::assertLessThanOrEqual(100, $result->score);
        self::assertSame([
            'invalid_title',
            'missing_year',
            'missing_country',
            'missing_genres',
            'missing_poster',
            'missing_description',
            'data_conflicts',
            'source_never_checked',
            'missing_video',
        ], $result->issueCodes());
        self::assertSame('critical', $result->severity->value);
    }

    #[Test]
    public function only_unproven_irrelevant_imported_tags_are_suspicious(): void
    {
        $facts = CatalogTitleQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'title' => 'Цветок зла',
            'original_title' => 'The Flower of Evil',
            'description' => 'Криминальная дорама о полиции, психопате и серийных убийствах.',
            'genres' => ['детектив', 'криминал', 'мелодрама', 'триллер'],
            'tags' => [
                ['name' => 'дорама', 'type' => 'imported', 'has_current_provenance' => true],
                ['name' => 'полиция', 'type' => 'imported', 'has_current_provenance' => true],
                ['name' => 'Гномы', 'type' => 'imported', 'has_current_provenance' => false],
                ['name' => 'друиды', 'type' => 'imported', 'has_current_provenance' => false],
                ['name' => 'криминал', 'type' => 'imported', 'has_current_provenance' => false],
                ['name' => 'Выбор редакции', 'type' => 'editorial', 'has_current_provenance' => false],
            ],
        ]);

        $result = $this->evaluator()->evaluate($facts);
        $issue = $result->issuesFor(CatalogQualityIssueCategory::SuspiciousTags)->sole();

        self::assertSame('suspicious_tags', $issue->code);
        self::assertSame(2, $issue->evidence['count']);
        self::assertSame(['Гномы', 'друиды'], $issue->evidence['examples']);
    }

    #[Test]
    public function substantial_episode_gaps_conflicting_counts_and_stale_checks_are_reported(): void
    {
        $facts = CatalogTitleQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'seasons' => [
                [
                    'number' => 1,
                    'regular_episode_count' => 10,
                    'minimum_episode_number' => 1,
                    'maximum_episode_number' => 20,
                    'distinct_episode_number_count' => 10,
                    'released_episode_count' => 12,
                    'total_episode_count' => 8,
                ],
            ],
            'last_source_checked_at' => CarbonImmutable::parse('2026-05-01T00:00:00Z'),
            'evaluated_at' => CarbonImmutable::parse('2026-07-26T00:00:00Z'),
        ]);

        $result = $this->evaluator()->evaluate($facts);

        self::assertContains('suspicious_episodes', $result->issueCodes());
        self::assertContains('stale_source', $result->issueCodes());
    }

    #[Test]
    public function contiguous_high_episode_numbers_are_not_treated_as_a_gap(): void
    {
        $facts = CatalogTitleQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'seasons' => [
                [
                    'number' => 1,
                    'regular_episode_count' => 10,
                    'minimum_episode_number' => 185,
                    'maximum_episode_number' => 194,
                    'distinct_episode_number_count' => 10,
                    'released_episode_count' => 194,
                    'total_episode_count' => 194,
                ],
            ],
        ]);

        self::assertNotContains(
            'suspicious_episodes',
            $this->evaluator()->evaluate($facts)->issueCodes(),
        );
    }

    #[Test]
    public function translation_provider_rating_media_and_season_anomalies_are_explained(): void
    {
        $seasons = [];

        for ($number = 1; $number <= 51; $number++) {
            $seasons[] = [
                'number' => $number,
                'regular_episode_count' => 0,
                'minimum_episode_number' => null,
                'maximum_episode_number' => null,
                'distinct_episode_number_count' => 0,
                'released_episode_count' => null,
                'total_episode_count' => null,
            ];
        }

        $facts = CatalogTitleQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'original_title' => '???',
            'provider_field_values' => [
                'title' => 'Другое название',
                'original_title' => 'True title',
                'description' => $this->healthyFacts()->description,
                'poster_url' => $this->healthyFacts()->posterUrl,
            ],
            'ratings' => [
                ['provider' => 'broken', 'rating' => 11.5, 'votes' => -1],
                ['provider' => 'one', 'rating' => 2.0, 'votes' => 10],
                ['provider' => 'two', 'rating' => 8.0, 'votes' => 10],
            ],
            'media' => [
                'published_playable_count' => 2,
                'never_checked_count' => 0,
                'latest_checked_at' => CarbonImmutable::parse('2026-06-01T00:00:00Z'),
            ],
            'seasons' => $seasons,
        ]);

        $codes = $this->evaluator()->evaluate($facts)->issueCodes();

        self::assertContains('data_conflicts', $codes);
        self::assertContains('strange_translation', $codes);
        self::assertContains('invalid_ratings', $codes);
        self::assertContains('rating_conflict', $codes);
        self::assertContains('stale_video_check', $codes);
        self::assertContains('suspicious_season_count', $codes);
        self::assertSame(
            CatalogQualityIssueCategory::Stale,
            $this->evaluator()->evaluate($facts)
                ->issuesFor(CatalogQualityIssueCategory::Stale)
                ->firstWhere('code', 'stale_video_check')
                ?->category,
        );
    }

    private function evaluator(): CatalogTitleQualityEvaluator
    {
        return new CatalogTitleQualityEvaluator([
            'stale_source_after_days' => 30,
            'stale_media_after_days' => 14,
            'rating_disagreement_threshold' => 3.0,
            'season_count_warning' => 50,
            'season_number_warning' => 100,
            'episode_gap_minimum' => 3,
            'episode_gap_ratio' => 0.10,
        ]);
    }

    private function healthyFacts(): CatalogTitleQualityFacts
    {
        return CatalogTitleQualityFacts::fromArray([
            'catalog_title_id' => 42,
            'title' => 'Настоящий детектив',
            'original_title' => 'True Detective',
            'year' => 2014,
            'description' => 'Детективы расследуют сложное преступление и возвращаются к нему спустя годы.',
            'poster_url' => 'https://images.example.com/poster.jpg',
            'countries' => ['США'],
            'genres' => ['детектив', 'криминал', 'драма'],
            'tags' => [
                ['name' => 'детектив', 'type' => 'imported', 'has_current_provenance' => true],
            ],
            'provider_field_values' => [
                'title' => 'Настоящий детектив',
                'original_title' => 'True Detective',
                'description' => 'Детективы расследуют сложное преступление и возвращаются к нему спустя годы.',
                'poster_url' => 'https://images.example.com/poster.jpg',
            ],
            'seasons' => [
                [
                    'number' => 1,
                    'regular_episode_count' => 8,
                    'minimum_episode_number' => 1,
                    'maximum_episode_number' => 8,
                    'distinct_episode_number_count' => 8,
                    'released_episode_count' => 8,
                    'total_episode_count' => 8,
                ],
            ],
            'media' => [
                'published_playable_count' => 1,
                'never_checked_count' => 0,
                'latest_checked_at' => CarbonImmutable::parse('2026-07-25T00:00:00Z'),
            ],
            'ratings' => [
                ['provider' => 'kinopoisk', 'rating' => 8.8, 'votes' => 100000],
                ['provider' => 'imdb', 'rating' => 8.9, 'votes' => 500000],
            ],
            'last_source_checked_at' => CarbonImmutable::parse('2026-07-25T00:00:00Z'),
            'evaluated_at' => CarbonImmutable::parse('2026-07-26T00:00:00Z'),
        ]);
    }
}
