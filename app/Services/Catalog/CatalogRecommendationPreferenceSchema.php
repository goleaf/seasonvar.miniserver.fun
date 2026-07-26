<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationPreferenceSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('catalog_recommendation_feedback_details')
            && Schema::hasTable('catalog_recommendation_preferences')
            && Schema::hasTable('catalog_recommendation_hidden_genres');
    }
}
