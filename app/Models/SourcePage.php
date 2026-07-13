<?php

namespace App\Models;

use Database\Factories\SourcePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_id',
    'url',
    'url_hash',
    'page_type',
    'http_status',
    'content_hash',
    'etag',
    'last_modified_header',
    'last_crawled_at',
    'last_changed_at',
    'parse_status',
    'error_message',
    'discovered_from_url',
    'import_status',
    'missing_data_flags',
    'retry_after_at',
    'failure_count',
    'last_import_run_id',
    'last_imported_at',
    'import_claim_token',
    'import_claimed_at',
    'import_claim_expires_at',
    'import_claim_run_id',
    'metadata_parser_version',
    'metadata_attempted_version',
    'metadata_parsed_at',
    'metadata_presence',
])]
class SourcePage extends Model
{
    /** @use HasFactory<SourcePageFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'metadata_parser_version' => 0,
        'metadata_attempted_version' => 0,
    ];

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasOne<CatalogTitle, $this>
     */
    public function catalogTitle(): HasOne
    {
        return $this->hasOne(CatalogTitle::class);
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function linkedSeasons(): HasMany
    {
        return $this->hasMany(Season::class, 'source_url_hash', 'url_hash');
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /**
     * @return HasMany<CatalogTitleReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(CatalogTitleReview::class);
    }

    /**
     * @return HasMany<SourcePageSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SourcePageSnapshot::class);
    }

    /**
     * @return HasOne<SourcePageSnapshot, $this>
     */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(SourcePageSnapshot::class)
            ->ofMany([
                'captured_at' => 'max',
                'id' => 'max',
            ]);
    }

    /**
     * @return HasMany<SeasonvarImportEvent, $this>
     */
    public function importEvents(): HasMany
    {
        return $this->hasMany(SeasonvarImportEvent::class);
    }

    /**
     * @return BelongsTo<SeasonvarImportRun, $this>
     */
    public function lastImportRun(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportRun::class, 'last_import_run_id');
    }

    /**
     * @return BelongsTo<SeasonvarImportRun, $this>
     */
    public function importClaimRun(): BelongsTo
    {
        return $this->belongsTo(SeasonvarImportRun::class, 'import_claim_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'last_crawled_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'missing_data_flags' => 'array',
            'retry_after_at' => 'datetime',
            'failure_count' => 'integer',
            'last_imported_at' => 'datetime',
            'import_claimed_at' => 'datetime',
            'import_claim_expires_at' => 'datetime',
            'metadata_parser_version' => 'integer',
            'metadata_attempted_version' => 'integer',
            'metadata_parsed_at' => 'datetime',
            'metadata_presence' => 'array',
        ];
    }
}
