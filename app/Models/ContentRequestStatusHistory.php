<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_request_id', 'actor_id', 'from_status', 'to_status', 'public_reason', 'private_note', 'idempotency_key'])]
final class ContentRequestStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<ContentRequest, $this> */
    public function contentRequest(): BelongsTo
    {
        return $this->belongsTo(ContentRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['from_status' => ContentRequestStatus::class, 'to_status' => ContentRequestStatus::class];
    }
}
