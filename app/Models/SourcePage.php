<?php

namespace App\Models;

use Database\Factories\SourcePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
])]
class SourcePage extends Model
{
    /** @use HasFactory<SourcePageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_crawled_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }
}
