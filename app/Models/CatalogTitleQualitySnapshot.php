<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogQualitySeverity;
use Carbon\CarbonImmutable;
use Database\Factories\CatalogTitleQualitySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $catalog_title_id
 * @property int $quality_score
 * @property CatalogQualitySeverity $severity
 * @property int $issue_count
 * @property int $critical_count
 * @property bool $needs_refresh
 * @property int $scoring_version
 * @property CarbonImmutable|null $last_source_checked_at
 * @property CarbonImmutable $evaluated_at
 */
#[Fillable([
    'catalog_title_id',
    'quality_score',
    'severity',
    'issue_count',
    'critical_count',
    'needs_refresh',
    'scoring_version',
    'last_source_checked_at',
    'evaluated_at',
])]
final class CatalogTitleQualitySnapshot extends Model
{
    /** @use HasFactory<CatalogTitleQualitySnapshotFactory> */
    use HasFactory;

    protected $primaryKey = 'catalog_title_id';

    public $incrementing = false;

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return HasMany<CatalogTitleQualityIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(
            CatalogTitleQualityIssue::class,
            'catalog_title_id',
            'catalog_title_id',
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quality_score' => 'integer',
            'severity' => CatalogQualitySeverity::class,
            'issue_count' => 'integer',
            'critical_count' => 'integer',
            'needs_refresh' => 'boolean',
            'scoring_version' => 'integer',
            'last_source_checked_at' => 'immutable_datetime',
            'evaluated_at' => 'immutable_datetime',
        ];
    }
}
