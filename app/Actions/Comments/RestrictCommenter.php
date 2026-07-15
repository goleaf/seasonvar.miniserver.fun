<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\AdminAuditAction;
use App\Enums\CommentRestrictionReason;
use App\Enums\CommentRestrictionType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\CommentRestriction;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Comments\CommentModerationAudit;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RestrictCommenter
{
    /** @var list<int> */
    private const ALLOWED_DURATIONS = [1, 3, 7, 30, 90];

    public function __construct(
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly CommentModerationAudit $audit,
    ) {}

    public function handle(
        User $moderator,
        int $userId,
        CommentRestrictionType|string $type,
        CommentRestrictionReason|string $reason,
        ?int $durationDays,
        mixed $privateNote = null,
    ): CommentRestriction {
        Gate::forUser($moderator)->authorize('manage-comments');
        $restrictedUser = User::query()->findOrFail($userId);

        if ($restrictedUser->is($moderator)) {
            throw new CommentActionException('comments.errors.cannot_restrict_self');
        }

        $type = is_string($type) ? CommentRestrictionType::tryFrom($type) : $type;
        $reason = is_string($reason) ? CommentRestrictionReason::tryFrom($reason) : $reason;

        if (! $type instanceof CommentRestrictionType || ! $reason instanceof CommentRestrictionReason) {
            throw new CommentActionException('comments.errors.invalid_restriction');
        }

        if ($type === CommentRestrictionType::Temporary && ! in_array($durationDays, self::ALLOWED_DURATIONS, true)) {
            throw new CommentActionException('comments.errors.invalid_restriction_duration');
        }

        if ($type === CommentRestrictionType::Permanent) {
            $durationDays = null;
        }

        $privateNote = UserPlainText::description($privateNote);

        if ($privateNote !== null && Str::length($privateNote) > 2_000) {
            throw new CommentActionException('comments.errors.moderator_note_too_long', ['maximum' => 2_000]);
        }

        $restriction = DB::transaction(function () use (
            $moderator,
            $restrictedUser,
            $type,
            $reason,
            $durationDays,
            $privateNote,
        ): CommentRestriction {
            User::query()->whereKey($restrictedUser->id)->lockForUpdate()->firstOrFail();
            $active = CommentRestriction::query()
                ->active()
                ->where('user_id', $restrictedUser->id)
                ->latest('id')
                ->first();

            if ($active !== null
                && $active->type === $type
                && $active->reason_code === $reason
                && $active->private_note === $privateNote
                && $this->sameDuration($active, $durationDays)) {
                return $active;
            }

            CommentRestriction::query()
                ->active()
                ->where('user_id', $restrictedUser->id)
                ->update([
                    'revoked_by_id' => $moderator->id,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return CommentRestriction::query()->create([
                'user_id' => $restrictedUser->id,
                'moderator_id' => $moderator->id,
                'type' => $type,
                'reason_code' => $reason,
                'private_note' => $privateNote,
                'starts_at' => now(),
                'expires_at' => $durationDays !== null ? now()->addDays($durationDays) : null,
            ]);
        }, attempts: 3);

        if ($restriction->wasRecentlyCreated) {
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::CommentRestrictionApplied,
                $restriction,
                AdminAuditRecorder::ABSENT_VERSION,
                $this->audit->restriction($restriction),
                ['restriction_type', 'reason_code', 'starts_at', 'expires_at', 'moderator_note'],
            );
        }

        return $restriction;
    }

    private function sameDuration(CommentRestriction $restriction, ?int $durationDays): bool
    {
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
