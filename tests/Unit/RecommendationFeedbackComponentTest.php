<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\Components\Catalog\RecommendationFeedback;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationFeedbackComponentTest extends TestCase
{
    #[DataProvider('invalidInputs')]
    public function test_component_rejects_untrusted_action_names_and_invalid_title_ids(
        int $titleId,
        string $action,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new RecommendationFeedback($titleId, $action);
    }

    /** @return array<string, array{int, string}> */
    public static function invalidInputs(): array
    {
        return [
            'zero title' => [0, 'setFeedback'],
            'negative title' => [-1, 'setRecommendationFeedback'],
            'unknown action' => [1, 'deleteUser'],
            'injected action' => [1, "setFeedback'); alert(1); ('"],
        ];
    }

    public function test_component_allows_only_full_and_compact_variants(): void
    {
        $this->assertSame('compact', (new RecommendationFeedback(
            titleId: 1,
            action: 'setRecommendationFeedback',
            variant: 'compact',
        ))->variant);

        $this->expectException(InvalidArgumentException::class);

        new RecommendationFeedback(
            titleId: 1,
            action: 'setRecommendationFeedback',
            variant: 'hover-only',
        );
    }
}
