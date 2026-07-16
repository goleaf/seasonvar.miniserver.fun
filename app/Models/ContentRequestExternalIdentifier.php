<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentRequestExternalProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_request_id', 'provider', 'identifier', 'normalized_identifier'])]
final class ContentRequestExternalIdentifier extends Model
{
    /** @return BelongsTo<ContentRequest, $this> */
    public function contentRequest(): BelongsTo
    {
        return $this->belongsTo(ContentRequest::class);
    }

    protected function casts(): array
    {
        return ['provider' => ContentRequestExternalProvider::class];
    }
}
