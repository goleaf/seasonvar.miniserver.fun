<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogTitleQualityIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $catalog_title_id
 * @property int|null $catalog_quality_run_id
 * @property string $code
 * @property CatalogQualityIssueCategory $category
 * @property CatalogQualitySeverity $severity
 * @property int $penalty
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $first_detected_at
 * @property CarbonImmutable $last_detected_at
 */
#[Fillable([
    'catalog_title_id',
    'catalog_quality_run_id',
    'code',
    'category',
    'severity',
    'penalty',
    'evidence',
    'first_detected_at',
    'last_detected_at',
])]
final class CatalogTitleQualityIssue extends Model
{
    /** @use HasFactory<CatalogTitleQualityIssueFactory> */
    use HasFactory;

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<CatalogQualityRun, $this> */
    public function qualityRun(): BelongsTo
    {
        return $this->belongsTo(CatalogQualityRun::class, 'catalog_quality_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => CatalogQualityIssueCategory::class,
            'catalog_quality_run_id' => 'integer',
            'severity' => CatalogQualitySeverity::class,
            'penalty' => 'integer',
            'evidence' => 'array',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
        ];
    }
}
