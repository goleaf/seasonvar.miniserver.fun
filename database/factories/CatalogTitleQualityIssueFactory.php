<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualityIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogTitleQualityIssue>
 */
final class CatalogTitleQualityIssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'catalog_title_id' => CatalogTitle::factory(),
            'code' => fake()->unique()->slug(2),
            'category' => CatalogQualityIssueCategory::DataConflicts,
            'severity' => CatalogQualitySeverity::Warning,
            'penalty' => 5,
            'evidence' => [],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ];
    }
}
