<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\AdminAuditAction;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Reviews\ReviewModerationAudit;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use Illuminate\Support\Facades\Gate;

final class RevokeCatalogTitleReviewRestriction
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewRateLimiter $rateLimiter,
        private readonly AdminAuditRecorder $auditRecorder,
        private readonly ReviewModerationAudit $audit,
    ) {}

    public function handle(User $moderator, int $restrictionId): CatalogTitleReviewRestriction
    {
        if (! $this->schema->writable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        Gate::forUser($moderator)->authorize('manage-reviews');
        $restriction = CatalogTitleReviewRestriction::query()->findOrFail($restrictionId);

        if ($restriction->revoked_at !== null) {
            return $restriction;
        }

        $this->rateLimiter->hit('restrict', $moderator, 'restriction:'.$restriction->id);
        $beforeVersion = $this->audit->restriction($restriction);
        $restriction->forceFill([
            'revoked_by_id' => $moderator->id,
            'revoked_at' => now(),
        ])->save();
        $this->auditRecorder->record(
            $moderator,
            AdminAuditAction::ReviewRestrictionRevoked,
            $restriction,
            $beforeVersion,
            $this->audit->restriction($restriction),
            ['revoked_at'],
        );

        return $restriction;
    }
}
