<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AdminPermission;
use App\Enums\CatalogTitleRelationSource;
use App\Enums\CatalogTitleRelationType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRelation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CatalogTitleRelationService
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogCacheInvalidator $cache,
    ) {}

    /** @return Collection<int, CatalogTitleRelation> */
    public function forTitle(CatalogTitle $title, ?User $viewer, int $limit = 12): Collection
    {
        if (! Schema::hasTable('catalog_title_relations')) {
            return collect();
        }

        return CatalogTitleRelation::query()
            ->where('source_title_id', $title->id)
            ->where('is_active', true)
            ->where('target_title_id', '!=', $title->id)
            ->whereIn('target_title_id', $this->titles->visibleTo($viewer)->select('id'))
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(max(1, min(24, $limit)))
            ->get()
            ->unique('target_title_id')
            ->values();
    }

    public function saveEditorial(
        User $actor,
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
        int $priority = 100,
        bool $locked = true,
    ): CatalogTitleRelation {
        Gate::forUser($actor)->authorize(AdminPermission::RecommendationsManage->value);

        return $this->save(
            source: $source,
            target: $target,
            type: $type,
            sourceKind: CatalogTitleRelationSource::Editorial,
            priority: $priority,
            locked: $locked,
        );
    }

    public function saveImported(
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
        string $providerKey,
        int $priority = 200,
    ): ?CatalogTitleRelation {
        if (CatalogTitleRelation::query()
            ->where('source_title_id', $source->id)
            ->where('target_title_id', $target->id)
            ->where('relation_type', $type->value)
            ->where('source', CatalogTitleRelationSource::Editorial->value)
            ->where('is_locked', true)
            ->exists()) {
            return null;
        }

        return $this->save(
            source: $source,
            target: $target,
            type: $type,
            sourceKind: CatalogTitleRelationSource::ImportedProvider,
            priority: $priority,
            locked: false,
            providerKey: $providerKey,
        );
    }

    public function removeEditorial(User $actor, CatalogTitleRelation $relation): void
    {
        Gate::forUser($actor)->authorize(AdminPermission::RecommendationsManage->value);

        abort_unless($relation->relationSource() === CatalogTitleRelationSource::Editorial, 404);

        DB::transaction(function () use ($relation): void {
            $sourceId = (int) $relation->source_title_id;
            $targetId = (int) $relation->target_title_id;
            $inverse = $relation->relationType()->inverse();

            $relation->delete();
            CatalogTitleRelation::query()
                ->where('source_title_id', $targetId)
                ->where('target_title_id', $sourceId)
                ->where('relation_type', $inverse->value)
                ->where('source', CatalogTitleRelationSource::Editorial->value)
                ->delete();

            $this->cache->catalogChanged([$sourceId, $targetId]);
        }, attempts: 3);
    }

    /**
     * Preserve explicit relations before the importer force-deletes a duplicate title.
     * The source remains part of the identity, so imported rows cannot replace editorial rows.
     */
    public function mergeTitle(CatalogTitle $canonical, CatalogTitle $duplicate): void
    {
        if ($canonical->is($duplicate) || ! Schema::hasTable('catalog_title_relations')) {
            return;
        }

        CatalogTitleRelation::query()
            ->where(function (Builder $query) use ($duplicate): void {
                $query
                    ->where('source_title_id', $duplicate->id)
                    ->orWhere('target_title_id', $duplicate->id);
            })
            ->orderByDesc('is_locked')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->each(function (CatalogTitleRelation $relation) use ($canonical, $duplicate): void {
                $sourceId = (int) $relation->source_title_id === (int) $duplicate->id
                    ? (int) $canonical->id
                    : (int) $relation->source_title_id;
                $targetId = (int) $relation->target_title_id === (int) $duplicate->id
                    ? (int) $canonical->id
                    : (int) $relation->target_title_id;

                if ($sourceId === $targetId) {
                    $relation->delete();

                    return;
                }

                $existing = CatalogTitleRelation::query()
                    ->where('id', '!=', $relation->id)
                    ->where('source_title_id', $sourceId)
                    ->where('target_title_id', $targetId)
                    ->where('relation_type', $relation->relationType()->value)
                    ->where('source', $relation->relationSource()->value)
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill([
                        'provider_key' => $existing->provider_key ?: $relation->provider_key,
                        'priority' => min((int) $existing->priority, (int) $relation->priority),
                        'is_locked' => $existing->is_locked || $relation->is_locked,
                        'is_active' => $existing->is_active || $relation->is_active,
                    ])->save();
                    $relation->delete();

                    return;
                }

                $relation->forceFill([
                    'source_title_id' => $sourceId,
                    'target_title_id' => $targetId,
                ])->save();
            });

        CatalogTitleRelation::query()
            ->where('source_title_id', $canonical->id)
            ->where('target_title_id', $canonical->id)
            ->delete();
    }

    private function save(
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
        CatalogTitleRelationSource $sourceKind,
        int $priority,
        bool $locked,
        ?string $providerKey = null,
    ): CatalogTitleRelation {
        $this->assertEligiblePair($source, $target, $type);
        $priority = max(0, min(65_535, $priority));
        $providerKey = $providerKey !== null ? str($providerKey)->trim()->limit(64, '')->toString() : null;

        return DB::transaction(function () use ($locked, $priority, $providerKey, $source, $sourceKind, $target, $type): CatalogTitleRelation {
            $relation = $this->upsertRelation($source, $target, $type, $sourceKind, $priority, $locked, $providerKey);

            $this->upsertRelation($target, $source, $type->inverse(), $sourceKind, $priority, $locked, $providerKey);

            $this->cache->catalogChanged([$source->id, $target->id]);

            return $relation;
        }, attempts: 3);
    }

    private function upsertRelation(
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
        CatalogTitleRelationSource $sourceKind,
        int $priority,
        bool $locked,
        ?string $providerKey,
    ): CatalogTitleRelation {
        return CatalogTitleRelation::query()->updateOrCreate([
            'source_title_id' => $source->id,
            'target_title_id' => $target->id,
            'relation_type' => $type->value,
            'source' => $sourceKind->value,
        ], [
            'provider_key' => $providerKey,
            'priority' => $priority,
            'is_locked' => $locked,
            'is_active' => true,
        ]);
    }

    private function assertEligiblePair(
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
    ): void {
        if ($source->is($target) || $source->trashed() || $target->trashed()) {
            throw ValidationException::withMessages([
                'relation' => __('recommendations.admin.invalid_relation'),
            ]);
        }

        if (in_array($type, [CatalogTitleRelationType::Sequel, CatalogTitleRelationType::Prequel], true)
            && $this->createsDirectionalCycle($source, $target, $type)) {
            throw ValidationException::withMessages([
                'relation' => __('recommendations.admin.relation_cycle'),
            ]);
        }
    }

    private function createsDirectionalCycle(
        CatalogTitle $source,
        CatalogTitle $target,
        CatalogTitleRelationType $type,
    ): bool {
        $forwardType = $type === CatalogTitleRelationType::Sequel
            ? CatalogTitleRelationType::Sequel
            : CatalogTitleRelationType::Prequel;
        $frontier = [$target->id];
        $visited = [];

        for ($depth = 0; $depth < 50 && $frontier !== []; $depth++) {
            if (in_array($source->id, $frontier, true)) {
                return true;
            }

            $visited = array_values(array_unique([...$visited, ...$frontier]));
            $frontier = CatalogTitleRelation::query()
                ->whereIn('source_title_id', $frontier)
                ->where('relation_type', $forwardType->value)
                ->where('is_active', true)
                ->whereNotIn('target_title_id', $visited)
                ->limit(500)
                ->pluck('target_title_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return false;
    }
}
