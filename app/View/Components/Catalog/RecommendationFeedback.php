<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

final class RecommendationFeedback extends Component
{
    public function __construct(
        public readonly int $titleId,
        public readonly string $action,
    ) {
        if ($titleId < 1) {
            throw new InvalidArgumentException('Recommendation feedback title ID must be positive.');
        }

        if (! in_array($action, ['setFeedback', 'setRecommendationFeedback'], true)) {
            throw new InvalidArgumentException('Unsupported recommendation feedback action.');
        }
    }

    public function render(): View
    {
        return view('components.catalog.recommendation-feedback');
    }
}
