<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonvarPreparedPageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property SeasonvarPreparedPageStatus $status
 * @property array<string, mixed>|null $payload
 * @property list<array<string, mixed>>|null $warnings
 * @property Carbon|null $prepared_at
 * @property Carbon|null $applied_at
 * @property SeasonvarImportRun $run
 * @property SeasonvarImportTitleGroup $group
 * @property SourcePage $sourcePage
 */
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

    /**
     * @param  array<string, int>  $applicationResult
     */
    public function markApplied(array $applicationResult = []): void
    {
        $payload = $this->payload ?? [];
        $payload['_application_result'] = $this->normalizeApplicationResult($applicationResult);

        $this->update([
            'status' => SeasonvarPreparedPageStatus::Applied,
            'payload' => $payload,
            'applied_at' => now(),
        ]);
    }

    /**
     * @return array{media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function applicationResult(): array
    {
        $result = data_get($this->payload, '_application_result');

        return $this->normalizeApplicationResult(is_array($result) ? $result : []);
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

    /**
     * @param  array<string, mixed>  $result
     * @return array{media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    private function normalizeApplicationResult(array $result): array
    {
        return [
            'media_attached' => max(0, (int) ($result['media_attached'] ?? 0)),
            'media_updated' => max(0, (int) ($result['media_updated'] ?? 0)),
            'media_skipped' => max(0, (int) ($result['media_skipped'] ?? 0)),
            'media_failed' => max(0, (int) ($result['media_failed'] ?? 0)),
        ];
    }
}
