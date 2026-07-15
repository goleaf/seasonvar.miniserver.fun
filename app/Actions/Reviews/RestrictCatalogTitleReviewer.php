<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\AdminAuditAction;
use App\Enums\ReviewRestrictionReason;
use App\Enums\ReviewRestrictionType;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Reviews\ReviewModerationAudit;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RestrictCatalogTitleReviewer
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly ReviewModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $userId,
        ReviewRestrictionType|string $type,
        ReviewRestrictionReason|string $reason,
        ?int $durationDays,
        mixed $privateNote,
    ): CatalogTitleReviewRestriction {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        Gate::forUser($moderator)->authorize('manage-reviews');
        $type = is_string($type) ? ReviewRestrictionType::tryFrom($type) : $type;
        $reason = is_string($reason) ? ReviewRestrictionReason::tryFrom($reason) : $reason;

        if (! $type instanceof ReviewRestrictionType || ! $reason instanceof ReviewRestrictionReason) {
            throw new ReviewActionException('reviews.errors.invalid_restriction');
        }

        $reviewer = User::query()->findOrFail($userId);

        if ($reviewer->is($moderator)) {
            throw new ReviewActionException('reviews.errors.cannot_restrict_self');
        }

        if ($type === ReviewRestrictionType::Temporary
            && ($durationDays === null || $durationDays < 1 || $durationDays > 365)) {
            throw new ReviewActionException('reviews.errors.invalid_restriction_duration');
        }

        if ($type === ReviewRestrictionType::Permanent) {
            $durationDays = null;
        }

        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new ReviewActionException('reviews.errors.private_note_too_long', ['maximum' => 2_000]);
        }

        $this->rateLimiter->hit('restrict', $moderator, 'user:'.$reviewer->id);

        $restriction = DB::transaction(function () use (
            $reviewer,
            $moderator,
            $type,
            $reason,
            $privateNote,
            $durationDays,
        ): CatalogTitleReviewRestriction {
            User::query()->whereKey($reviewer->id)->lockForUpdate()->firstOrFail();
            $active = CatalogTitleReviewRestriction::query()
                ->active()
                ->where('user_id', $reviewer->id)
                ->latest('id')
                ->first();

            if ($active !== null
                && $active->type === $type
                && $active->reason_code === $reason
                && $this->sameDuration($active, $durationDays)) {
                return $active;
            }

            CatalogTitleReviewRestriction::query()
                ->active()
                ->where('user_id', $reviewer->id)
                ->update([
                    'revoked_by_id' => $moderator->id,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return CatalogTitleReviewRestriction::query()->create([
                'user_id' => $reviewer->id,
                'moderator_id' => $moderator->id,
                'type' => $type,
                'reason_code' => $reason,
                'private_note' => $privateNote,
                'starts_at' => now(),
                'expires_at' => $type === ReviewRestrictionType::Temporary
                    ? now()->addDays((int) $durationDays)
                    : null,
            ]);
        }, attempts: 3);

        if ($restriction->wasRecentlyCreated) {
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::ReviewRestrictionApplied,
                $restriction,
                AdminAuditRecorder::ABSENT_VERSION,
                $this->audit->restriction($restriction),
                ['restriction_type', 'reason_code', 'starts_at', 'expires_at', 'moderator_note'],
            );
        }

        return $restriction;
    }

    private function sameDuration(
        CatalogTitleReviewRestriction $restriction,
        ?int $durationDays,
    ): bool {
        if ($durationDays === null) {
            return $restriction->expires_at === null;
        }

        return $restriction->expires_at !== null
            && $restriction->expires_at->between(
                now()->addDays($durationDays)->subMinutes(5),
                now()->addDays($durationDays)->addMinutes(5),
            );
    }
}
