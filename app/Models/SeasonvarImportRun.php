<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'mode',
    'status',
    'argument',
    'force',
    'forever',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'forever' => 'boolean',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
