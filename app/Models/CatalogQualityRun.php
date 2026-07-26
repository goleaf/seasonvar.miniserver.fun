<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogQualityRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CatalogQualityRunStatus $status
 * @property string $trigger
 * @property int $scoring_version
 * @property int $requested_limit
 * @property int $processed_count
 * @property int $issue_count
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $failure_code
 */
#[Fillable([
    'status',
    'trigger',
    'scoring_version',
    'requested_limit',
    'processed_count',
    'issue_count',
    'started_at',
    'completed_at',
    'failure_code',
])]
final class CatalogQualityRun extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'running',
        'processed_count' => 0,
        'issue_count' => 0,
    ];

    /** @return HasMany<CatalogTitleQualitySnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(CatalogTitleQualitySnapshot::class);
    }

    /** @return HasMany<CatalogTitleQualityIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(CatalogTitleQualityIssue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CatalogQualityRunStatus::class,
            'scoring_version' => 'integer',
            'requested_limit' => 'integer',
            'processed_count' => 'integer',
            'issue_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
