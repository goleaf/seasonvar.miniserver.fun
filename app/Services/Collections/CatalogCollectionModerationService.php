<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\AdminAuditAction;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionModerationService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function moderate(User $actor, CatalogCollection $collection, CatalogCollectionModerationStatus $status): CatalogCollection
    {
        Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);
        $result = DB::transaction(function () use ($actor, $collection, $status): array {
            $locked = CatalogCollection::query()->withTrashed()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);
            $featured = $locked->is_featured
                && $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public;
            $shouldBePublished = $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public;
            $publishedAt = $shouldBePublished ? ($locked->published_at ?? now()) : null;
            $moderationChanged = $locked->moderation_status !== $status;
            $featuredChanged = $locked->is_featured !== $featured;
            $changed = $moderationChanged
                || $featuredChanged
                || ($shouldBePublished && $locked->published_at === null)
                || (! $shouldBePublished && $locked->published_at !== null);

            if (! $changed) {
                return ['collection' => $locked, 'changed' => false];
            }

            $before = $this->fingerprint($locked);
            $changedFields = array_values(array_filter([
                $moderationChanged ? 'moderation_status' : null,
                $featuredChanged ? 'is_featured' : null,
            ]));

            if ($changedFields === []) {
                $changedFields = ['moderation_status'];
            }

            $locked->forceFill([
                'moderation_status' => $status,
                'is_featured' => $featured,
                'published_at' => $publishedAt,
                'content_version' => $locked->content_version + 1,
            ])->save();
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionModerated,
                $locked,
                $before,
                $this->fingerprint($locked),
                $changedFields,
            );

            return ['collection' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollection $collection */
        $collection = $result['collection'];

        $this->cache->changed($collection);

        return $collection->refresh();
    }

    public function feature(User $actor, CatalogCollection $collection, bool $featured): CatalogCollection
    {
        Gate::forUser($actor)->authorize('feature', $collection);
        $result = DB::transaction(function () use ($actor, $collection, $featured): array {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('feature', $locked);

            if ($featured && ($locked->type !== CatalogCollectionType::Editorial
                || $locked->visibility !== CatalogCollectionVisibility::Public
                || $locked->moderation_status !== CatalogCollectionModerationStatus::Approved)) {
                throw ValidationException::withMessages(['feature' => [__('collections.errors.feature_requires_public')]]);
            }

            if ($locked->is_featured === $featured) {
                return ['collection' => $locked, 'changed' => false];
            }

            $before = $this->fingerprint($locked);
            $locked->forceFill([
                'is_featured' => $featured,
                'content_version' => $locked->content_version + 1,
            ])->save();
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionFeatured,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['is_featured'],
            );

            return ['collection' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollection $collection */
        $collection = $result['collection'];

        $this->cache->changed($collection);

        return $collection->refresh();
    }

    public function resolveReport(
        User $actor,
        CatalogCollectionReport $report,
        CatalogCollectionReportStatus $status,
        ?string $note = null,
    ): CatalogCollectionReport {
        Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);
        abort_if($status === CatalogCollectionReportStatus::Open, 422);
        $note = UserPlainText::description($note);

        $result = DB::transaction(function () use ($actor, $report, $status, $note): CatalogCollectionReport {
            $locked = CatalogCollectionReport::query()->lockForUpdate()->findOrFail($report->id);
            $lockedCollection = $locked->collection()->withTrashed()->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);

            if ($locked->status !== CatalogCollectionReportStatus::Open) {
                return $locked;
            }

            $before = $this->fingerprint($lockedCollection);
            $locked->forceFill([
                'moderator_id' => $actor->id,
                'status' => $status,
                'resolution_note' => $note,
                'resolved_at' => now(),
            ])->save();
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionReportResolved,
                $lockedCollection,
                $before,
                hash('sha256', implode('|', [
                    $this->fingerprint($lockedCollection),
                    (string) $locked->id,
                    $status->value,
                ])),
                ['report_status'],
            );

            return $locked;
        }, attempts: 3);

        return $result->refresh();
    }

    public function fingerprint(CatalogCollection $collection): string
    {
        return hash('sha256', json_encode([
            'id' => $collection->id,
            'version' => $collection->content_version,
            'moderation' => $collection->moderation_status->value,
            'featured' => $collection->is_featured,
            'updated_at' => $collection->updated_at?->toAtomString(),
        ], JSON_THROW_ON_ERROR));
    }
}
