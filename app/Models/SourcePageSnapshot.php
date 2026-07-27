<?php

namespace App\Models;

use App\Services\Seasonvar\SeasonvarImportPayloadCodec;
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
    'html_blob',
    'html_codec',
    'html_uncompressed_bytes',
    'captured_at',
])]
class SourcePageSnapshot extends Model
{
    public function body(): string
    {
        $attributes = $this->getAttributes();
        $blob = $attributes['html_blob'] ?? null;
        $codec = $attributes['html_codec'] ?? null;
        $uncompressedBytes = $attributes['html_uncompressed_bytes'] ?? null;

        if (is_string($blob)
            && is_string($codec)
            && is_numeric($uncompressedBytes)
        ) {
            return app(SeasonvarImportPayloadCodec::class)
                ->decodeString(
                    $blob,
                    $codec,
                    (int) $uncompressedBytes,
                );
        }

        return (string) ($attributes['html'] ?? '');
    }

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
            'http_status' => 'integer',
            'body_bytes' => 'integer',
            'html_uncompressed_bytes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
