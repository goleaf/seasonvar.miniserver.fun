<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonvarPreparedPageStatus;
use App\Services\Seasonvar\SeasonvarImportPayloadCodec;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property SeasonvarPreparedPageStatus $status
 * @property array<string, mixed>|null $payload
 * @property string|null $payload_blob
 * @property string|null $payload_codec
 * @property int|null $payload_uncompressed_bytes
 * @property array<string, int>|null $application_result
 * @property list<array<string, mixed>>|null $warnings
 * @property Carbon|null $last_enqueue_attempt_at
 * @property int $enqueue_attempts
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
    'payload_blob',
    'payload_codec',
    'payload_uncompressed_bytes',
    'application_result',
    'warnings',
    'last_error',
    'last_enqueue_attempt_at',
    'enqueue_attempts',
    'prepared_at',
    'applied_at',
])]
class SeasonvarImportPreparedPage extends Model
{
    protected $attributes = [
        'enqueue_attempts' => 0,
    ];

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

    public function beginPreparing(): bool
    {
        $changed = self::query()
            ->whereKey($this->id)
            ->where('status', SeasonvarPreparedPageStatus::Queued->value)
            ->update([
                'status' => SeasonvarPreparedPageStatus::Preparing->value,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($changed === 1) {
            $this->status = SeasonvarPreparedPageStatus::Preparing;
            $this->last_error = null;
        }

        return $changed === 1;
    }

    public function returnToQueue(): bool
    {
        $changed = self::query()
            ->whereKey($this->id)
            ->where('status', SeasonvarPreparedPageStatus::Preparing->value)
            ->update([
                'status' => SeasonvarPreparedPageStatus::Queued->value,
                'updated_at' => now(),
            ]);

        if ($changed === 1) {
            $this->status = SeasonvarPreparedPageStatus::Queued;
        }

        return $changed === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $warnings
     */
    public function markPrepared(array $payload, array $warnings, string $contentHash, int $parserVersion): void
    {
        $storage = [
            'payload' => $payload,
            'payload_blob' => null,
            'payload_codec' => null,
            'payload_uncompressed_bytes' => null,
        ];

        if ((bool) config('seasonvar.import.compact_storage_write_enabled', false)) {
            $encoded = app(SeasonvarImportPayloadCodec::class)
                ->encodeJson($payload);
            $storage = [
                'payload' => null,
                'payload_blob' => $encoded['blob'],
                'payload_codec' => $encoded['codec'],
                'payload_uncompressed_bytes' => $encoded['uncompressed_bytes'],
            ];
        }

        $this->update([
            'status' => SeasonvarPreparedPageStatus::Prepared,
            'content_hash' => $contentHash,
            'parser_version' => $parserVersion,
            ...$storage,
            'warnings' => $warnings,
            'last_error' => null,
            'application_result' => null,
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
        $normalized = $this->normalizeApplicationResult($applicationResult);
        $attributes = $this->getAttributes();
        $updates = [
            'status' => SeasonvarPreparedPageStatus::Applied,
            'application_result' => $normalized,
            'applied_at' => now(),
        ];

        if (! (bool) config('seasonvar.import.compact_storage_write_enabled', false)
            && array_key_exists('payload', $attributes)
            && $this->payload !== null
            && ($attributes['payload_blob'] ?? null) === null
        ) {
            $payload = $this->payload;
            $payload['_application_result'] = $normalized;
            $updates['payload'] = $payload;
        }

        $this->update($updates);
    }

    /**
     * @return array{media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function applicationResult(): array
    {
        $attributes = $this->getAttributes();
        $storedResult = array_key_exists('application_result', $attributes)
            ? $this->getAttribute('application_result')
            : null;
        $result = $storedResult
            ?? data_get($this->decodedPayload(), '_application_result');

        return $this->normalizeApplicationResult(is_array($result) ? $result : []);
    }

    /** @return array<string, mixed> */
    public function decodedPayload(): array
    {
        $attributes = $this->getAttributes();
        $blob = $attributes['payload_blob'] ?? null;
        $codec = $attributes['payload_codec'] ?? null;
        $uncompressedBytes = $attributes['payload_uncompressed_bytes'] ?? null;

        if (is_string($blob)
            && is_string($codec)
            && is_numeric($uncompressedBytes)
        ) {
            return app(SeasonvarImportPayloadCodec::class)
                ->decodeJson(
                    $blob,
                    $codec,
                    (int) $uncompressedBytes,
                );
        }

        return array_key_exists('payload', $attributes)
            ? ($this->payload ?? [])
            : [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SeasonvarPreparedPageStatus::class,
            'parser_version' => 'integer',
            'payload' => 'array',
            'payload_uncompressed_bytes' => 'integer',
            'application_result' => 'array',
            'warnings' => 'array',
            'last_enqueue_attempt_at' => 'datetime',
            'enqueue_attempts' => 'integer',
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
