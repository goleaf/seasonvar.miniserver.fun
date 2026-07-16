<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 */
#[Fillable([
    'public_id', 'technical_issue_id', 'technical_issue_message_id', 'uploader_id', 'disk', 'path',
    'display_name', 'mime_type', 'extension', 'size_bytes', 'width', 'height', 'content_hash',
])]
final class TechnicalIssueAttachment extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function technicalIssue(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssue::class);
    }

    /** @return BelongsTo<TechnicalIssueMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TechnicalIssueMessage::class, 'technical_issue_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
