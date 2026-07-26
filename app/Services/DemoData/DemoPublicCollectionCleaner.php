<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogRecommendationBuild;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Services\Seasonvar\SeasonvarImportActivity;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class DemoPublicCollectionCleaner
{
    private const MINIMUM_MATCH_BASIS_POINTS = 9_000;

    public function __construct(
        private DemoStableValue $stable,
        private SeasonvarImportActivity $importActivity,
        private CatalogCollectionCacheInvalidator $cache,
    ) {}

    /** @return array<string, int> */
    public function inspect(DemoDataOptions $options): array
    {
        return $this->scan($options)['summary'];
    }

    /** @return array<string, int> */
    public function repair(DemoDataOptions $options): array
    {
        $scan = $this->scan($options);
        $summary = $scan['summary'];

        if ($scan['candidate_ids'] === []) {
            return [...$summary, 'demo_collections_quarantined' => 0];
        }

        $this->assertSafeToRepair($summary);
        $candidateIds = $scan['candidate_ids'];
        $expectedOwners = $scan['expected_owners'];
        $changedIds = DB::transaction(function () use ($candidateIds, $expectedOwners): array {
            $collections = CatalogCollection::query()
                ->whereKey($candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'public_id',
                    'owner_id',
                    'visibility',
                    'is_featured',
                    'published_at',
                ])
                ->filter(fn (CatalogCollection $collection): bool => $this->isExactOwnedCollection(
                    $collection,
                    $expectedOwners,
                ))
                ->filter(fn (CatalogCollection $collection): bool => $this->needsQuarantine($collection));
            $ids = $collections->modelKeys();

            if ($ids === []) {
                return [];
            }

            CatalogCollection::query()
                ->whereKey($ids)
                ->update([
                    'visibility' => CatalogCollectionVisibility::Private->value,
                    'is_featured' => false,
                    'published_at' => null,
                    'content_version' => DB::raw('content_version + 1'),
                    'updated_at' => now(),
                ]);

            return array_map('intval', $ids);
        }, attempts: 3);

        if ($changedIds !== []) {
            $this->cache->changedMany(CatalogCollection::query()->whereKey($changedIds)->get());
        }

        return [...$summary, 'demo_collections_quarantined' => count($changedIds)];
    }

    /**
     * @return array{
     *     summary: array<string, int>,
     *     candidate_ids: list<int>,
     *     expected_owners: array<string, int>
     * }
     */
    private function scan(DemoDataOptions $options): array
    {
        $expectedOwners = $this->expectedOwners($options);
        $collections = CatalogCollection::query()
            ->whereIn('public_id', array_keys($expectedOwners))
            ->withCount('items')
            ->orderBy('id')
            ->get([
                'id',
                'public_id',
                'owner_id',
                'visibility',
                'is_featured',
                'published_at',
            ])
            ->filter(fn (CatalogCollection $collection): bool => $this->isExactOwnedCollection(
                $collection,
                $expectedOwners,
            ))
            ->values();
        $candidates = $collections
            ->filter(fn (CatalogCollection $collection): bool => $this->needsQuarantine($collection));
        $expectedCount = count($expectedOwners);
        $matchedCount = $collections->count();
        $maximumPublicItems = max(
            1,
            (int) config('catalog-collections.maximum_public_items_per_collection', 500),
        );

        return [
            'summary' => [
                'demo_expected_collections' => $expectedCount,
                'demo_matched_collections' => $matchedCount,
                'demo_match_basis_points' => $expectedCount > 0
                    ? intdiv($matchedCount * 10_000, $expectedCount)
                    : 0,
                'demo_public_collections' => $collections
                    ->where('visibility', CatalogCollectionVisibility::Public)
                    ->count(),
                'demo_unlisted_collections' => $collections
                    ->where('visibility', CatalogCollectionVisibility::Unlisted)
                    ->count(),
                'demo_oversized_collections' => $collections
                    ->filter(fn (CatalogCollection $collection): bool => (int) $collection->items_count > $maximumPublicItems)
                    ->count(),
                'demo_quarantine_candidates' => $candidates->count(),
            ],
            'candidate_ids' => $candidates
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            'expected_owners' => $expectedOwners,
        ];
    }

    /** @return array<string, int> */
    private function expectedOwners(DemoDataOptions $options): array
    {
        $emails = collect(range(1, $options->userCount))
            ->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $users = User::query()
            ->whereIn('email', $emails->keys())
            ->get(['id', 'email'])
            ->keyBy('email');

        if ($users->isEmpty()) {
            return [];
        }

        if ($users->count() !== $options->userCount) {
            throw new LogicException('Набор известных demo accounts неполон; collection repair остановлен.');
        }

        $expected = [];

        foreach ($emails as $email => $userIndex) {
            $user = $users->get($email);

            if (! $user instanceof User) {
                throw new LogicException('Не удалось подтвердить exact demo owner; collection repair остановлен.');
            }

            $count = $this->stable->integer(
                "organization:user:{$userIndex}:collection-count",
                $options->collectionMinimum,
                $options->collectionMaximum,
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $expected[$this->stable->uuid(
                    "organization:user:{$userIndex}:collection:{$ordinal}",
                )] = (int) $user->id;
            }
        }

        return $expected;
    }

    /** @param array<string, int> $expectedOwners */
    private function isExactOwnedCollection(
        CatalogCollection $collection,
        array $expectedOwners,
    ): bool {
        $expectedOwner = $expectedOwners[(string) $collection->public_id] ?? null;

        return $expectedOwner !== null && $expectedOwner === (int) $collection->owner_id;
    }

    private function needsQuarantine(CatalogCollection $collection): bool
    {
        return $collection->visibility !== CatalogCollectionVisibility::Private
            || $collection->published_at !== null
            || $collection->is_featured;
    }

    /** @param array<string, int> $summary */
    private function assertSafeToRepair(array $summary): void
    {
        if ($this->importActivity->active()) {
            throw new LogicException('Quarantine demo collections запрещён во время активного импорта Seasonvar.');
        }

        if (CatalogCollectionSyncRun::query()
            ->where('status', CatalogCollectionSyncStatus::Running->value)
            ->exists()) {
            throw new LogicException('Quarantine demo collections запрещён во время синхронизации source collections.');
        }

        if (CatalogRecommendationBuild::query()->whereIn('status', ['building', 'evaluated'])->exists()) {
            throw new LogicException('Quarantine demo collections запрещён при незавершённой сборке рекомендаций.');
        }

        if ($summary['demo_expected_collections'] < 1
            || $summary['demo_match_basis_points'] < self::MINIMUM_MATCH_BASIS_POINTS) {
            throw new LogicException('Exact demo collection footprint не подтверждён; repair остановлен.');
        }
    }
}
