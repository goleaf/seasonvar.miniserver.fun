<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogRecommendationReason;
use App\Services\Catalog\CatalogRecommendationPresenter;
use Tests\TestCase;

final class CatalogRecommendationPresenterTest extends TestCase
{
    public function test_editorial_collection_reason_has_a_distinct_russian_explanation(): void
    {
        app()->setLocale('ru');
        $presenter = app(CatalogRecommendationPresenter::class);

        $this->assertSame(
            ['Одна подборка'],
            $presenter->storedSimilarityReasons([
                'collection_signal' => ['score' => 180],
            ]),
        );
        $this->assertSame(
            CatalogRecommendationReason::SharedEditorialCollection,
            $presenter->storedSimilarityExplanations([
                'collection_signal' => ['score' => 180],
            ])[0]->reason,
        );
    }
}
