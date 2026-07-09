<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_page_id',
    'seasonvar_import_run_id',
    'url',
    'content_hash',
    'http_status',
    'body_bytes',
    'html',
    'captured_at',
])]
class SourcePageSnapshot extends Model
{
    /**
     * @return BelongsTo<SourcePage, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    /**
     * @return BelongsTo<SeasonvarImportRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportRun::class, 'seasonvar_import_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }
}
