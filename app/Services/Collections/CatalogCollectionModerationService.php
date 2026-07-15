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
        $before = $this->fingerprint($collection);

        DB::transaction(function () use ($collection, $status): void {
            $locked = CatalogCollection::query()->withTrashed()->lockForUpdate()->findOrFail($collection->id);
            $locked->moderation_status = $status;
            $locked->is_featured = $locked->is_featured
                && $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public;
            $locked->published_at = $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public
                ? ($locked->published_at ?? now())
                : null;
            $locked->content_version++;
            $locked->save();
        }, attempts: 3);

        $collection = CatalogCollection::query()->withTrashed()->findOrFail($collection->id);
        $this->audit->record(
            $actor,
            AdminAuditAction::CollectionModerated,
            $collection,
            $before,
            $this->fingerprint($collection),
            ['moderation_status'],
        );
        $this->cache->changed($collection);

        return $collection;
    }

    public function feature(User $actor, CatalogCollection $collection, bool $featured): CatalogCollection
    {
        Gate::forUser($actor)->authorize('feature', $collection);
        $before = '';
        $collection = DB::transaction(function () use (&$before, $collection, $featured): CatalogCollection {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($featured && ($locked->type !== CatalogCollectionType::Editorial
                || $locked->visibility !== CatalogCollectionVisibility::Public
                || $locked->moderation_status !== CatalogCollectionModerationStatus::Approved)) {
                throw ValidationException::withMessages(['feature' => [__('collections.errors.feature_requires_public')]]);
            }

            $before = $this->fingerprint($locked);
            $locked->forceFill([
                'is_featured' => $featured,
                'content_version' => $locked->content_version + 1,
            ])->save();

            return $locked;
        }, attempts: 3);
        $this->audit->record(
            $actor,
            AdminAuditAction::CollectionFeatured,
            $collection,
            $before,
            $this->fingerprint($collection),
            ['is_featured'],
        );
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
        $collection = $report->collection()->withTrashed()->firstOrFail();
        $before = $this->fingerprint($collection);
        $note = UserPlainText::description($note);

        DB::transaction(function () use ($actor, $report, $status, $note): void {
            $locked = CatalogCollectionReport::query()->lockForUpdate()->findOrFail($report->id);
            $locked->forceFill([
                'moderator_id' => $actor->id,
                'status' => $status,
                'resolution_note' => $note,
                'resolved_at' => now(),
            ])->save();
        }, attempts: 3);

        $this->audit->record(
            $actor,
            AdminAuditAction::CollectionReportResolved,
            $collection,
            $before,
            hash('sha256', implode('|', [
                $this->fingerprint($collection),
                (string) $report->id,
                $status->value,
            ])),
            ['report_status'],
        );

        return $report->refresh();
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
