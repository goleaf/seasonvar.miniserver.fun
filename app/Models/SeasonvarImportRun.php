<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
    'started_at',
    'finished_at',
])]
class SeasonvarImportRun extends Model
{
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
