<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\AdminAuditAction;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Collections\Quality\CatalogCollectionQualityAssessor;
use App\Support\UserPlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogCollectionModerationService
{
    public function __construct(
        private readonly CatalogCollectionCacheInvalidator $cache,
        private readonly AdminAuditRecorder $audit,
        private readonly CatalogCollectionPublicationReadiness $readiness,
        private readonly CatalogCollectionQualityAssessor $quality,
        private readonly CatalogCollectionSchema $schema,
    ) {}

    public function moderate(User $actor, CatalogCollection $collection, CatalogCollectionModerationStatus $status): CatalogCollection
    {
        Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);

        if ($status === CatalogCollectionModerationStatus::Approved
            && $collection->visibility === CatalogCollectionVisibility::Public) {
            $this->ensureQualityAvailable();

            if ($this->qualityNeedsRefresh($collection)) {
                $this->quality->refreshCollection((int) $collection->id);
            }
        }

        $result = DB::transaction(function () use ($actor, $collection, $status): array {
            $locked = CatalogCollection::query()->withTrashed()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('moderate', CatalogCollection::class);
            $featured = $locked->is_featured
                && $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public;
            $shouldBePublished = $status === CatalogCollectionModerationStatus::Approved
                && $locked->visibility === CatalogCollectionVisibility::Public;

            if ($shouldBePublished && ! CatalogCollection::query()
                ->eligibleForPublicListing()
                ->whereKey($locked->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'moderation' => [__('collections.errors.public_quality_not_ready', [
                        'count' => max(
                            1,
                            (int) config(
                                'catalog-collections.maximum_public_items_per_collection',
                                500,
                            ),
                        ),
                    ])],
                ]);
            }

            if ($shouldBePublished && ! $locked->meetsPublicQualityThreshold()) {
                throw ValidationException::withMessages([
                    'moderation' => [__('collections.errors.collection_quality_score_not_ready', [
                        'score' => min(
                            100,
                            max(
                                0,
                                (int) config(
                                    'catalog-collections.quality.minimum_public_score',
                                    60,
                                ),
                            ),
                        ),
                    ])],
                ]);
            }

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

            $nextContentVersion = $locked->content_version + 1;
            $carryCurrentQuality = $locked->hasCurrentQuality();
            $carryEditorialVerification = $locked->editorially_verified_at !== null
                && $locked->editorially_verified_content_version === $locked->content_version;
            $locked->forceFill([
                'moderation_status' => $status,
                'is_featured' => $featured,
                'published_at' => $publishedAt,
                'content_version' => $nextContentVersion,
                ...$this->carriedQualityAttributes(
                    $locked,
                    $nextContentVersion,
                    $carryCurrentQuality,
                    $carryEditorialVerification,
                ),
            ])->save();

            if ($this->schema->qualityAvailable() && $carryCurrentQuality) {
                CatalogCollectionItem::query()
                    ->where('catalog_collection_id', $locked->id)
                    ->where('quality_content_version', $nextContentVersion - 1)
                    ->update([
                        'quality_content_version' => $nextContentVersion,
                        'updated_at' => now(),
                    ]);
            }
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

        if ($featured) {
            $this->ensureQualityAvailable();

            if ($this->qualityNeedsRefresh($collection)) {
                $this->quality->refreshCollection((int) $collection->id);
            }
        }

        $result = DB::transaction(function () use ($actor, $collection, $featured): array {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('feature', $locked);

            if ($locked->is_featured === $featured) {
                return ['collection' => $locked, 'changed' => false];
            }

            if ($featured && ! $this->readiness->evaluate($locked)['ready']) {
                throw ValidationException::withMessages(['feature' => [__('collections.errors.feature_not_ready')]]);
            }

            $before = $this->fingerprint($locked);
            $nextContentVersion = $locked->content_version + 1;
            $carryCurrentQuality = $locked->hasCurrentQuality();
            $carryEditorialVerification = $locked->editorially_verified_at !== null
                && $locked->editorially_verified_content_version === $locked->content_version;
            $locked->forceFill([
                'is_featured' => $featured,
                'content_version' => $nextContentVersion,
                ...$this->carriedQualityAttributes(
                    $locked,
                    $nextContentVersion,
                    $carryCurrentQuality,
                    $carryEditorialVerification,
                ),
            ])->save();

            if ($this->schema->qualityAvailable() && $carryCurrentQuality) {
                CatalogCollectionItem::query()
                    ->where('catalog_collection_id', $locked->id)
                    ->where('quality_content_version', $nextContentVersion - 1)
                    ->update([
                        'quality_content_version' => $nextContentVersion,
                        'updated_at' => now(),
                    ]);
            }
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

        if ($result['changed']) {
            $this->cache->changed($collection);
        }

        return $collection->refresh();
    }

    public function verifyQuality(
        User $actor,
        CatalogCollection $collection,
        bool $verified,
    ): CatalogCollection {
        Gate::forUser($actor)->authorize('feature', $collection);
        $this->ensureQualityAvailable();

        if ($verified && $this->qualityNeedsRefresh($collection)) {
            $this->quality->refreshCollection((int) $collection->id);
        }

        $result = DB::transaction(function () use ($actor, $collection, $verified): array {
            $locked = CatalogCollection::query()->lockForUpdate()->findOrFail($collection->id);
            Gate::forUser($actor)->authorize('feature', $locked);
            $currentlyVerified = $locked->editorially_verified_at !== null
                && $locked->editorially_verified_content_version === $locked->content_version;

            if ($verified && ! $locked->meetsPublicQualityThreshold()) {
                throw ValidationException::withMessages([
                    'quality' => [__('collections.errors.collection_quality_score_not_ready', [
                        'score' => $this->minimumPublicQualityScore(),
                    ])],
                ]);
            }

            if ($currentlyVerified === $verified) {
                return ['collection' => $locked, 'changed' => false];
            }

            $before = $this->fingerprint($locked);
            $locked->forceFill([
                'editorially_verified_at' => $verified ? now() : null,
                'editorially_verified_by_id' => $verified ? $actor->id : null,
                'editorially_verified_content_version' => $verified
                    ? $locked->content_version
                    : null,
            ])->save();
            $this->audit->record(
                $actor,
                AdminAuditAction::CollectionQualityVerified,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['editorially_verified_at'],
            );

            return ['collection' => $locked, 'changed' => true];
        }, attempts: 3);

        /** @var CatalogCollection $verifiedCollection */
        $verifiedCollection = $result['collection'];

        if ($result['changed']) {
            $this->quality->refreshCollection((int) $verifiedCollection->id);
        }

        return $verifiedCollection->refresh();
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
            'editorially_verified_at' => $collection->editorially_verified_at?->toAtomString(),
            'editorially_verified_content_version' => $collection->editorially_verified_content_version,
            'updated_at' => $collection->updated_at?->toAtomString(),
        ], JSON_THROW_ON_ERROR));
    }

    private function qualityNeedsRefresh(CatalogCollection $collection): bool
    {
        return ! $collection->hasCurrentQuality()
            || $collection->quality_evaluated_at === null
            || $collection->quality_evaluated_at->isBefore(
                now()->subDays(max(
                    1,
                    (int) config('catalog-collections.quality.stale_after_days', 14),
                )),
            );
    }

    private function minimumPublicQualityScore(): int
    {
        return min(
            100,
            max(
                0,
                (int) config('catalog-collections.quality.minimum_public_score', 60),
            ),
        );
    }

    /**
     * @return array{
     *     quality_content_version?: int|null,
     *     editorially_verified_content_version?: int|null
     * }
     */
    private function carriedQualityAttributes(
        CatalogCollection $collection,
        int $nextContentVersion,
        bool $carryCurrentQuality,
        bool $carryEditorialVerification,
    ): array {
        if (! $this->schema->qualityAvailable()) {
            return [];
        }

        return [
            'quality_content_version' => $carryCurrentQuality
                ? $nextContentVersion
                : $collection->quality_content_version,
            'editorially_verified_content_version' => $carryEditorialVerification
                ? $nextContentVersion
                : $collection->editorially_verified_content_version,
        ];
    }

    private function ensureQualityAvailable(): void
    {
        if ($this->schema->qualityAvailable()) {
            return;
        }

        throw ValidationException::withMessages([
            'quality' => [__('collections.errors.quality_system_unavailable')],
        ]);
    }
}
