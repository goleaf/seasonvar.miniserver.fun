<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonvarImportTitleGroupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property SeasonvarImportTitleGroupStatus $status
 * @property int $expected_pages
 * @property int $prepared_pages
 * @property int $failed_pages
 * @property int $applied_pages
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property SeasonvarImportRun $run
 * @property CatalogTitle|null $catalogTitle
 * @property Collection<int, SeasonvarImportPreparedPage> $preparedPages
 */
#[Fillable([
    'seasonvar_import_run_id',
    'catalog_title_id',
    'group_key_hash',
    'queue_name',
    'status',
    'expected_pages',
    'prepared_pages',
    'failed_pages',
    'applied_pages',
    'last_error',
    'started_at',
    'finished_at',
])]
class SeasonvarImportTitleGroup extends Model
{
    /** @return BelongsTo<SeasonvarImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportRun::class, 'seasonvar_import_run_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return HasMany<SeasonvarImportPreparedPage, $this> */
    public function preparedPages(): HasMany
    {
        return $this->hasMany(SeasonvarImportPreparedPage::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SeasonvarImportTitleGroupStatus::class,
            'expected_pages' => 'integer',
            'prepared_pages' => 'integer',
            'failed_pages' => 'integer',
            'applied_pages' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
