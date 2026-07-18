<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewRestriction;

final class ReviewModerationAudit
{
    public function review(CatalogTitleReview $review): string
    {
        return $this->fingerprint([
            'id' => $review->id,
            'status' => $review->status->value,
            'version' => $review->version,
            'body_hash' => $review->body_hash,
            'spoiler' => $review->is_spoiler,
            'moderation_reason' => $review->moderation_reason?->value,
            'moderator_note_hash' => $this->privateTextHash($review->moderator_note),
            'moderated_at' => $review->moderated_at?->toAtomString(),
            'deletion_reason' => $review->deletion_reason?->value,
            'deleted_by_id' => $review->deleted_by_id,
            'deleted_at' => $review->deleted_at?->toAtomString(),
        ]);
    }

    public function report(CatalogTitleReviewReport $report): string
    {
        return $this->fingerprint([
            'id' => $report->id,
            'review_id' => $report->catalog_title_review_id,
            'category' => $report->category->value,
            'status' => $report->status->value,
            'private_note_hash' => $this->privateTextHash($report->private_note),
            'resolved_at' => $report->resolved_at?->toAtomString(),
        ]);
    }

    public function restriction(CatalogTitleReviewRestriction $restriction): string
    {
        return $this->fingerprint([
            'id' => $restriction->id,
            'user_id' => $restriction->user_id,
            'type' => $restriction->type->value,
            'reason' => $restriction->reason_code->value,
            'starts_at' => $restriction->starts_at->toAtomString(),
            'expires_at' => $restriction->expires_at?->toAtomString(),
            'revoked_at' => $restriction->revoked_at?->toAtomString(),
            'private_note_hash' => $this->privateTextHash($restriction->private_note),
        ]);
    }

    private function privateTextHash(?string $value): ?string
    {
        return $value === null ? null : hash('sha256', $value);
    }

    /** @param array<string, bool|int|string|null> $state */
    private function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
