<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonvarPreparedPageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seasonvar_import_run_id',
    'seasonvar_import_title_group_id',
    'source_page_id',
    'status',
    'content_hash',
    'parser_version',
    'payload',
    'warnings',
    'last_error',
    'prepared_at',
    'applied_at',
])]
class SeasonvarImportPreparedPage extends Model
{
    /** @return BelongsTo<SeasonvarImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportRun::class, 'seasonvar_import_run_id');
    }

    /** @return BelongsTo<SeasonvarImportTitleGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportTitleGroup::class, 'seasonvar_import_title_group_id');
    }

    /** @return BelongsTo<SourcePage, $this> */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    public function markPreparing(): void
    {
        $this->update([
            'status' => SeasonvarPreparedPageStatus::Preparing,
            'last_error' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $warnings
     */
    public function markPrepared(array $payload, array $warnings, string $contentHash, int $parserVersion): void
    {
        $this->update([
            'status' => SeasonvarPreparedPageStatus::Prepared,
            'content_hash' => $contentHash,
            'parser_version' => $parserVersion,
            'payload' => $payload,
            'warnings' => $warnings,
            'last_error' => null,
            'prepared_at' => now(),
        ]);
    }

    public function markFailed(string $sanitizedError): void
    {
        $this->update([
            'status' => SeasonvarPreparedPageStatus::Failed,
            'last_error' => $sanitizedError,
        ]);
    }

    public function markApplied(): void
    {
        $this->update([
            'status' => SeasonvarPreparedPageStatus::Applied,
            'applied_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SeasonvarPreparedPageStatus::class,
            'parser_version' => 'integer',
            'payload' => 'array',
            'warnings' => 'array',
            'prepared_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
