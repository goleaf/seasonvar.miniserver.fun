<?php

namespace App\Models;

use App\Enums\SeasonvarImportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'mode',
    'execution_mode',
    'status',
    'argument',
    'force',
    'forever',
    'process_id',
    'process_host',
    'process_command',
    'cycles',
    'discovered',
    'stored',
    'selected',
    'parsed',
    'failed',
    'media_attached',
    'media_updated',
    'media_skipped',
    'media_failed',
    'summary',
    'last_error',
    'requested_by_user_id',
    'retry_of_run_id',
    'last_heartbeat_at',
    'cancel_requested_at',
    'started_at',
    'finished_at',
])]
class SeasonvarImportRun extends Model
{
    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<SeasonvarImportRun, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    /** @return HasMany<SeasonvarImportRun, $this> */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_run_id');
    }

    /**
     * @return HasMany<SeasonvarImportEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SeasonvarImportEvent::class);
    }

    /**
     * @return HasMany<SourcePageSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SourcePageSnapshot::class, 'seasonvar_import_run_id');
    }

    /**
     * @return HasMany<SeasonvarImportTitleGroup, $this>
     */
    public function titleGroups(): HasMany
    {
        return $this->hasMany(SeasonvarImportTitleGroup::class);
    }

    /**
     * @return HasMany<SeasonvarImportPreparedPage, $this>
     */
    public function preparedPages(): HasMany
    {
        return $this->hasMany(SeasonvarImportPreparedPage::class);
    }

    /**
     * @return HasMany<SourcePage, $this>
     */
    public function lastImportedSourcePages(): HasMany
    {
        return $this->hasMany(SourcePage::class, 'last_import_run_id');
    }

    /**
     * @return HasMany<SourcePage, $this>
     */
    public function claimedSourcePages(): HasMany
    {
        return $this->hasMany(SourcePage::class, 'import_claim_run_id');
    }

    public function statusValue(): SeasonvarImportStatus
    {
        return SeasonvarImportStatus::tryFrom((string) $this->status)
            ?? SeasonvarImportStatus::Failed;
    }

    public function completionStatus(): string
    {
        return ((int) $this->failed > 0 || (int) $this->media_failed > 0)
            ? SeasonvarImportStatus::Partial->value
            : SeasonvarImportStatus::Completed->value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'forever' => 'boolean',
            'process_id' => 'integer',
            'cycles' => 'integer',
            'discovered' => 'integer',
            'stored' => 'integer',
            'selected' => 'integer',
            'parsed' => 'integer',
            'failed' => 'integer',
            'media_attached' => 'integer',
            'media_updated' => 'integer',
            'media_skipped' => 'integer',
            'media_failed' => 'integer',
            'summary' => 'array',
            'last_heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
