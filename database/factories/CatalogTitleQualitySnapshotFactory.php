<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualitySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogTitleQualitySnapshot>
 */
final class CatalogTitleQualitySnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'catalog_title_id' => CatalogTitle::factory(),
            'quality_score' => fake()->numberBetween(60, 100),
            'severity' => CatalogQualitySeverity::Notice,
            'issue_count' => 1,
            'critical_count' => 0,
            'needs_refresh' => false,
            'scoring_version' => 1,
            'last_source_checked_at' => now(),
            'evaluated_at' => now(),
        ];
    }
}
