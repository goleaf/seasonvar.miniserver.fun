<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentRequestExternalProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_request_id', 'added_by_id', 'verified_by_id', 'url', 'url_hash', 'provider', 'is_public', 'verified_at'])]
final class ContentRequestSourceLink extends Model
{
    /** @return BelongsTo<ContentRequest, $this> */
    public function contentRequest(): BelongsTo
    {
        return $this->belongsTo(ContentRequest::class);
    }

    protected function casts(): array
    {
        return ['provider' => ContentRequestExternalProvider::class, 'is_public' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }
}
