<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Schema;

final class CatalogTasteOnboardingSchema
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasColumns('catalog_recommendation_preferences', [
            'onboarding_completed_at',
            'playback_preference',
            'completion_preference',
            'episode_length_preference',
        ])
            && Schema::hasTable('catalog_recommendation_onboarding_titles')
            && Schema::hasTable('catalog_recommendation_preferred_genres')
            && Schema::hasTable('catalog_recommendation_preferred_countries');
    }
}
