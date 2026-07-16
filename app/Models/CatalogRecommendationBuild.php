<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\CatalogRecommendationScoreRange;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'algorithm_version',
    'feature_version',
    'status',
    'metrics',
    'failure_message',
    'started_at',
    'completed_at',
    'activated_at',
])]
final class CatalogRecommendationBuild extends Model
{
    /** @return HasMany<CatalogRecommendationBuildRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(CatalogRecommendationBuildRow::class, 'build_id');
    }

    public function personalizationScoreRange(): ?CatalogRecommendationScoreRange
    {
        $algorithm = (string) config('recommendations.similarity_v6.algorithm_version', 'v6');
        $feature = (string) config('recommendations.similarity_v6.feature_version', 'tokens-v2');
        $metrics = is_array($this->metrics) ? $this->metrics : [];
        $minimum = $metrics['score_min'] ?? null;
        $median = $metrics['score_median'] ?? null;
        $p95 = $metrics['score_p95'] ?? null;

        if ($this->status !== 'active'
            || $this->algorithm_version !== $algorithm
            || $this->feature_version !== $feature
            || ! is_numeric($minimum)
            || ! is_numeric($median)
            || ! is_numeric($p95)) {
            return null;
        }

        $minimum = (int) $minimum;
        $median = (int) $median;
        $p95 = (int) $p95;

        if ($p95 <= $minimum || $median < $minimum || $median > $p95) {
            return null;
        }

        return new CatalogRecommendationScoreRange(
            minimum: $minimum,
            median: $median,
            p95: $p95,
            algorithmVersion: $this->algorithm_version,
            featureVersion: $this->feature_version,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }
}
