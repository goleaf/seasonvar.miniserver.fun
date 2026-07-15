<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\AdminAuditAction;
use App\Models\CommentRestriction;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Comments\CommentModerationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RevokeCommentRestriction
{
    public function __construct(
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly CommentModerationAudit $audit,
    ) {}

    public function handle(User $moderator, int $restrictionId): CommentRestriction
    {
        Gate::forUser($moderator)->authorize('manage-comments');
        [$restriction, $beforeVersion, $changed] = DB::transaction(function () use ($restrictionId, $moderator): array {
            $locked = CommentRestriction::query()->lockForUpdate()->findOrFail($restrictionId);

            if ($locked->revoked_at !== null) {
                return [$locked, $this->audit->restriction($locked), false];
            }

            $beforeVersion = $this->audit->restriction($locked);
            $locked->forceFill([
                'revoked_by_id' => $moderator->id,
                'revoked_at' => now(),
            ])->save();

            return [$locked, $beforeVersion, true];
        }, attempts: 3);

        if ($changed) {
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::CommentRestrictionRevoked,
                $restriction,
                $beforeVersion,
                $this->audit->restriction($restriction),
                ['revoked_at'],
            );
        }

        return $restriction;
    }
}
