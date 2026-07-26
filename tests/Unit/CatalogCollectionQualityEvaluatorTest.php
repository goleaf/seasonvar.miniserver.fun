<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CollectionQuality\CatalogCollectionQualityFacts;
use App\Services\Collections\Quality\CatalogCollectionQualityEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogCollectionQualityEvaluatorTest extends TestCase
{
    #[Test]
    public function coherent_bounded_editorial_collection_scores_above_public_threshold(): void
    {
        $result = $this->evaluator()->evaluate($this->healthyFacts());

        self::assertGreaterThanOrEqual(60, $result->score);
        self::assertLessThanOrEqual(100, $result->score);
        self::assertSame([], $result->issueCodes());
        self::assertSame(30, $result->components['theme']);
        self::assertArrayHasKey('engagement', $result->details);
    }

    #[Test]
    public function uncategorized_oversized_template_duplicate_is_hidden_by_a_clamped_score(): void
    {
        $facts = CatalogCollectionQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'category_present' => false,
            'category_active' => false,
            'item_count' => 3_500,
            'watchable_item_count' => 3_400,
            'average_theme_match' => 9,
            'precise_reason_count' => 0,
            'exact_duplicate_collection_id' => 17,
            'similar_text_collection_id' => 18,
            'repeated_text_count' => 24,
            'report_count' => 8,
        ]);

        $result = $this->evaluator()->evaluate($facts);

        self::assertSame(0, $result->score);
        self::assertSame([
            'missing_category',
            'too_many_items',
            'weak_theme',
            'exact_duplicate',
            'similar_text',
            'template_content',
            'user_reports',
        ], $result->issueCodes());
    }

    #[Test]
    public function saves_completions_and_returns_improve_only_the_bounded_trust_component(): void
    {
        $withoutEngagement = CatalogCollectionQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'save_count' => 0,
            'completion_count' => 0,
            'return_count' => 0,
        ]);

        $withEngagement = CatalogCollectionQualityFacts::fromArray([
            ...$withoutEngagement->toArray(),
            'save_count' => 20,
            'completion_count' => 12,
            'return_count' => 8,
        ]);

        $first = $this->evaluator()->evaluate($withoutEngagement);
        $second = $this->evaluator()->evaluate($withEngagement);

        self::assertGreaterThan($first->components['trust'], $second->components['trust']);
        self::assertLessThanOrEqual(20, $second->components['trust']);
        self::assertSame($first->components['metadata'], $second->components['metadata']);
        self::assertSame($first->components['structure'], $second->components['structure']);
        self::assertSame($first->components['theme'], $second->components['theme']);
    }

    #[Test]
    public function configured_template_threshold_controls_penalty_and_issue_creation(): void
    {
        $facts = CatalogCollectionQualityFacts::fromArray([
            ...$this->healthyFacts()->toArray(),
            'repeated_text_count' => 4,
        ]);
        $evaluator = new CatalogCollectionQualityEvaluator([
            'maximum_public_items' => 500,
            'minimum_public_score' => 60,
            'template_repetition_threshold' => 5,
        ]);

        $result = $evaluator->evaluate($facts);

        self::assertNotContains('template_content', $result->issueCodes());
        self::assertSame(0, $result->details['penalties']['template_content']);
    }

    private function evaluator(): CatalogCollectionQualityEvaluator
    {
        return new CatalogCollectionQualityEvaluator([
            'maximum_public_items' => 500,
            'minimum_public_score' => 60,
        ]);
    }

    private function healthyFacts(): CatalogCollectionQualityFacts
    {
        return CatalogCollectionQualityFacts::fromArray([
            'collection_id' => 42,
            'content_version' => 3,
            'name' => 'Корейские криминальные дорамы',
            'description' => 'Редакционная подборка напряжённых криминальных историй из Южной Кореи.',
            'category_present' => true,
            'category_active' => true,
            'item_count' => 24,
            'watchable_item_count' => 24,
            'average_theme_match' => 100,
            'precise_reason_count' => 24,
            'report_count' => 0,
            'save_count' => 20,
            'completion_count' => 12,
            'return_count' => 8,
            'exact_duplicate_collection_id' => null,
            'similar_text_collection_id' => null,
            'repeated_text_count' => 1,
            'source_managed' => false,
            'editorially_verified_current' => true,
        ]);
    }
}
