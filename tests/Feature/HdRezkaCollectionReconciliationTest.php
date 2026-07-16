<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionSourceMatch;
use App\DTOs\HdRezkaCollectionDefinition;
use App\DTOs\HdRezkaCollectionItemData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendationSignal;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Collections\Import\HdRezkaCollectionReconciler;
use App\Services\Collections\Import\HdRezkaCollectionSignalSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HdRezkaCollectionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['recommendations.similarity_v6.editorial_collection_signal_weight' => 280]);
    }

    public function test_initial_complete_reconciliation_creates_editorial_collection_source_and_ordered_membership(): void
    {
        $first = CatalogTitle::factory()->create(['title' => 'Первый фильм']);
        $second = CatalogTitle::factory()->create(['title' => 'Второй фильм']);
        $run = $this->syncRun();

        $result = $this->reconciler()->reconcile($run, $this->definition(), [
            $this->resolvedItem('101', 'Первый фильм', $first, 1),
            $this->resolvedItem('102', 'Второй фильм', $second, 2),
            $this->unresolvedItem('103', 'Не найден', CatalogCollectionSourceMatchStatus::Unmatched, 3),
        ], complete: true);

        $collection = CatalogCollection::query()->findOrFail($result['collection_id']);
        $source = CatalogCollectionSource::query()->firstOrFail();

        $this->assertTrue($result['created']);
        $this->assertTrue($result['membership_changed']);
        $this->assertSame(2, $result['matched']);
        $this->assertSame(0, $result['ambiguous']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame(0, $result['removed']);
        $this->assertNull($collection->owner_id);
        $this->assertSame(CatalogCollectionType::Editorial, $collection->type);
        $this->assertSame(CatalogCollectionVisibility::Public, $collection->visibility);
        $this->assertSame(CatalogCollectionModerationStatus::Approved, $collection->moderation_status);
        $this->assertSame('Про любовь', $collection->name);
        $this->assertNotNull($collection->published_at);
        $this->assertSame(1, $collection->content_version);
        $this->assertSame($collection->id, $source->catalog_collection_id);
        $this->assertSame($run->id, $source->last_seen_run_id);
        $this->assertNotNull($source->last_successful_sync_at);
        $this->assertSame(0, $source->retry_count);
        $this->assertSame(
            [$first->id, $second->id],
            $collection->items()->pluck('catalog_title_id')->all(),
        );
        $this->assertDatabaseCount('catalog_collection_source_items', 3);
    }

    public function test_exact_repeat_is_idempotent_and_does_not_bump_content_version(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Один фильм']);
        $first = $this->syncRun();
        $this->reconciler()->reconcile($first, $this->definition(), [
            $this->resolvedItem('101', 'Один фильм', $title, 1),
        ], complete: true);
        $collection = CatalogCollection::query()->firstOrFail();
        $version = $collection->content_version;
        $second = $this->syncRun();

        $result = $this->reconciler()->reconcile($second, $this->definition(), [
            $this->resolvedItem('101', 'Один фильм', $title, 1),
        ], complete: true);

        $this->assertFalse($result['created']);
        $this->assertFalse($result['membership_changed']);
        $this->assertSame($version, $collection->fresh()->content_version);
        $this->assertDatabaseCount('catalog_collections', 1);
        $this->assertDatabaseCount('catalog_collection_sources', 1);
        $this->assertDatabaseCount('catalog_collection_source_items', 1);
        $this->assertDatabaseCount('catalog_collection_items', 1);
    }

    public function test_order_changes_and_duplicate_remote_cards_produce_one_membership_row_per_title(): void
    {
        $first = CatalogTitle::factory()->create(['title' => 'Первый']);
        $second = CatalogTitle::factory()->create(['title' => 'Второй']);
        $this->reconciler()->reconcile($this->syncRun(), $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
            $this->resolvedItem('102', 'Второй', $second, 2),
        ], complete: true);
        $collection = CatalogCollection::query()->firstOrFail();
        $version = $collection->content_version;

        $result = $this->reconciler()->reconcile($this->syncRun(), $this->definition(), [
            $this->resolvedItem('102', 'Второй', $second, 1),
            $this->resolvedItem('103', 'Второй, дубль', $second, 2),
            $this->resolvedItem('101', 'Первый', $first, 3),
        ], complete: true);

        $this->assertTrue($result['membership_changed']);
        $this->assertSame($version + 1, $collection->fresh()->content_version);
        $this->assertSame(
            [$second->id, $first->id],
            $collection->items()->pluck('catalog_title_id')->all(),
        );
        $this->assertDatabaseCount('catalog_collection_items', 2);
        $this->assertDatabaseCount('catalog_collection_source_items', 3);
    }

    public function test_complete_run_removes_disappeared_membership(): void
    {
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $this->reconciler()->reconcile($this->syncRun(), $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
            $this->resolvedItem('102', 'Второй', $second, 2),
        ], complete: true);

        $result = $this->reconciler()->reconcile($this->syncRun(), $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
        ], complete: true);

        $this->assertSame(1, $result['removed']);
        $this->assertSame([$first->id], CatalogCollection::query()->firstOrFail()->items()->pluck('catalog_title_id')->all());
        $this->assertDatabaseCount('catalog_collection_source_items', 2);
    }

    public function test_partial_run_adds_seen_matches_but_retains_unseen_membership_and_retry_state(): void
    {
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $third = CatalogTitle::factory()->create();
        $completeRun = $this->syncRun();
        $this->reconciler()->reconcile($completeRun, $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
            $this->resolvedItem('102', 'Второй', $second, 2),
        ], complete: true);
        $partialRun = $this->syncRun();

        $result = $this->reconciler()->reconcile($partialRun, $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
            $this->resolvedItem('103', 'Третий', $third, 2),
        ], complete: false);
        $source = CatalogCollectionSource::query()->firstOrFail();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(
            [$first->id, $third->id, $second->id],
            $source->collection->items()->pluck('catalog_title_id')->all(),
        );
        $this->assertSame(1, $source->retry_count);
        $this->assertNotNull($source->last_retry_at);
        $this->assertSame($completeRun->id, $source->items()->where('source_item_key', '102')->value('last_seen_run_id'));
    }

    public function test_sync_preserves_local_moderation_publication_description_and_feature_state(): void
    {
        $title = CatalogTitle::factory()->create();
        $this->reconciler()->reconcile($this->syncRun(), $this->definition(), [
            $this->resolvedItem('101', 'Первый', $title, 1),
        ], complete: true);
        $collection = CatalogCollection::query()->firstOrFail();
        $collection->forceFill([
            'description' => 'Локальное описание',
            'visibility' => CatalogCollectionVisibility::Unlisted,
            'moderation_status' => CatalogCollectionModerationStatus::Hidden,
            'is_featured' => true,
            'published_at' => null,
        ])->save();
        $renamed = new HdRezkaCollectionDefinition(
            sourceKey: $this->definition()->sourceKey,
            name: 'Новое удалённое имя',
            path: '/xfsearch/collections/love/',
            coverPath: '/uploads/mini/14/aa/love.jpg',
            position: 1,
        );

        $this->reconciler()->reconcile($this->syncRun(), $renamed, [
            $this->resolvedItem('101', 'Первый', $title, 1),
        ], complete: true);
        $collection->refresh();

        $this->assertSame('Новое удалённое имя', $collection->name);
        $this->assertSame('Локальное описание', $collection->description);
        $this->assertSame(CatalogCollectionVisibility::Unlisted, $collection->visibility);
        $this->assertSame(CatalogCollectionModerationStatus::Hidden, $collection->moderation_status);
        $this->assertTrue($collection->is_featured);
        $this->assertNull($collection->published_at);
    }

    public function test_signals_are_upserted_for_current_membership_and_stale_provider_rows_are_deleted_only_after_complete_run(): void
    {
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $complete = $this->syncRun();
        $this->reconciler()->reconcile($complete, $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
            $this->resolvedItem('102', 'Второй', $second, 2),
        ], complete: true);
        $complete->update(['status' => CatalogCollectionSyncStatus::Completed, 'completed_at' => now()]);
        $signalSync = app(HdRezkaCollectionSignalSynchronizer::class);

        $initial = $signalSync->synchronizeForRun($complete->refresh());

        $this->assertSame(2, $initial['upserted']);
        $this->assertSame(0, $initial['deleted']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $initial['title_ids']);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $first->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
            'signal_key' => $this->definition()->sourceKey,
            'weight' => 280,
        ]);

        CatalogTitleRecommendationSignal::query()->create([
            'catalog_title_id' => $second->id,
            'source' => 'seasonvar',
            'signal_type' => 'editorial_collection',
            'signal_key' => 'other-provider',
            'weight' => 900,
            'observed_at' => now(),
        ]);
        CatalogTitleRecommendationSignal::query()->create([
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'related_title',
            'signal_key' => 'other-type',
            'weight' => 900,
            'observed_at' => now(),
        ]);

        $partial = $this->syncRun();
        $this->reconciler()->reconcile($partial, $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
        ], complete: false);
        $partial->update(['status' => CatalogCollectionSyncStatus::Partial, 'completed_at' => now()]);
        $partialResult = $signalSync->synchronizeForRun($partial->refresh());

        $this->assertSame(0, $partialResult['deleted']);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);

        $final = $this->syncRun();
        $this->reconciler()->reconcile($final, $this->definition(), [
            $this->resolvedItem('101', 'Первый', $first, 1),
        ], complete: true);
        $final->update(['status' => CatalogCollectionSyncStatus::Completed, 'completed_at' => now()]);
        $finalResult = $signalSync->synchronizeForRun($final->refresh());

        $this->assertSame(1, $finalResult['deleted']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $finalResult['title_ids']);
        $this->assertDatabaseMissing('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'seasonvar',
            'signal_type' => 'editorial_collection',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $second->id,
            'source' => 'hdrezka',
            'signal_type' => 'related_title',
        ]);
    }

    private function reconciler(): HdRezkaCollectionReconciler
    {
        return app(HdRezkaCollectionReconciler::class);
    }

    private function syncRun(): CatalogCollectionSyncRun
    {
        return CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => CatalogCollectionSyncStatus::Running,
            'counters' => [],
            'started_at' => now(),
        ]);
    }

    private function definition(): HdRezkaCollectionDefinition
    {
        return new HdRezkaCollectionDefinition(
            sourceKey: hash('sha256', 'love'),
            name: 'Про любовь',
            path: '/xfsearch/collections/love/',
            coverPath: '/uploads/mini/14/aa/love.jpg',
            position: 1,
        );
    }

    /** @return array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch} */
    private function resolvedItem(
        string $sourceId,
        string $name,
        CatalogTitle $title,
        int $position,
    ): array {
        return [
            'item' => $this->item($sourceId, $name, $position),
            'match' => new CatalogCollectionSourceMatch(
                status: CatalogCollectionSourceMatchStatus::Matched,
                catalogTitleId: $title->id,
                method: 'primary',
                confidence: 160,
                reasons: ['name' => 'primary', 'score' => 160],
            ),
        ];
    }

    /** @return array{item: HdRezkaCollectionItemData, match: CatalogCollectionSourceMatch} */
    private function unresolvedItem(
        string $sourceId,
        string $name,
        CatalogCollectionSourceMatchStatus $status,
        int $position,
    ): array {
        return [
            'item' => $this->item($sourceId, $name, $position),
            'match' => new CatalogCollectionSourceMatch(
                status: $status,
                catalogTitleId: null,
                method: $status === CatalogCollectionSourceMatchStatus::Ambiguous
                    ? 'insufficient_lead'
                    : 'no_exact_candidate',
                confidence: 0,
                reasons: ['candidate_count' => 0],
            ),
        ];
    }

    private function item(string $sourceId, string $name, int $position): HdRezkaCollectionItemData
    {
        $normalizer = app(CatalogSearchNormalizer::class);

        return new HdRezkaCollectionItemData(
            sourceItemKey: $sourceId,
            title: $name,
            normalizedTitleKey: $normalizer->key($name),
            year: 2024,
            type: 'film',
            countries: ['сша'],
            detailPath: "/{$sourceId}-title.html",
            page: 1,
            position: $position,
        );
    }
}
