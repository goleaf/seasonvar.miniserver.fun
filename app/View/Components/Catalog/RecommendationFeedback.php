<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

final class RecommendationFeedback extends Component
{
    private const VARIANTS = ['full', 'compact'];

    /**
     * @param array{
     *     genres?: list<array{id: int, name: string}>,
     *     countries?: list<array{id: int, name: string}>,
     *     actors?: list<array{id: int, name: string}>
     * } $feedbackOptions
     */
    public function __construct(
        public readonly int $titleId,
        public readonly string $action,
        public readonly array $feedbackOptions = [],
        public readonly string $variant = 'full',
    ) {
        if ($titleId < 1) {
            throw new InvalidArgumentException('Recommendation feedback title ID must be positive.');
        }

        if (! in_array($action, ['setFeedback', 'setRecommendationFeedback'], true)) {
            throw new InvalidArgumentException('Unsupported recommendation feedback action.');
        }

        if (! in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException('Unsupported recommendation feedback variant.');
        }
    }

    public function reasonAction(): string
    {
        return $this->action === 'setFeedback'
            ? 'setFeedbackReason'
            : 'setRecommendationFeedbackReason';
    }

    public function render(): View
    {
        return view('components.catalog.recommendation-feedback');
    }
}
