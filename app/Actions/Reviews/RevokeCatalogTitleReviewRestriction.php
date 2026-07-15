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
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($restriction, $moderator): CatalogTitleReviewRestriction {
            $locked = CatalogTitleReviewRestriction::query()
                ->lockForUpdate()
                ->findOrFail($restriction->id);

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $beforeVersion = $this->audit->restriction($locked);
            $locked->forceFill([
                'revoked_by_id' => $moderator->id,
                'revoked_at' => now(),
            ])->save();
            $this->auditRecorder->record(
                $moderator,
                AdminAuditAction::ReviewRestrictionRevoked,
                $locked,
                $beforeVersion,
                $this->audit->restriction($locked),
                ['revoked_at'],
            );

            return $locked;
        }, attempts: 3);
    }
}
