<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendationSignal;
use App\Services\Collections\CatalogCollectionPublicQualityRepairer;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionPublicQualityRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo-data.user_count' => 2,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.minimum_free_bytes' => 0,
            'demo-data.personal_tags.minimum' => 2,
            'demo-data.personal_tags.maximum' => 2,
            'demo-data.personal_tags.per_title_minimum' => 1,
            'demo-data.personal_tags.per_title_maximum' => 1,
            'demo-data.collections.minimum' => 2,
            'demo-data.collections.maximum' => 2,
            'demo-data.collections.per_title_minimum' => 1,
            'demo-data.collections.per_title_maximum' => 1,
            'demo-data.requests.minimum' => 1,
            'demo-data.requests.maximum' => 1,
            'demo-data.public_tag_target' => 2,
            'cache-architecture.stores.versions' => 'array',
        ]);
    }

    public function test_dry_run_and_force_quarantine_only_exact_demo_and_source_candidates(): void
    {
        CatalogTitle::factory()->count(4)->create();
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        app(DemoOrganizationStage::class)->run($options);

        CatalogCollection::query()->update([
            'visibility' => CatalogCollectionVisibility::Public->value,
            'moderation_status' => CatalogCollectionModerationStatus::Approved->value,
            'published_at' => now(),
        ]);
        $sourceTitle = CatalogTitle::factory()->create();
        $sourceCollection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => null,
            'name' => 'Legacy source collection',
            'slug' => 'legacy-source-collection',
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $sourceCollection->id,
            'catalog_title_id' => $sourceTitle->id,
            'position' => 1,
        ]);
        $source = CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => hash('sha256', 'legacy-quality-source'),
            'catalog_collection_id' => $sourceCollection->id,
            'source_path' => '/xfsearch/collections/legacy-quality-source/',
            'remote_name' => $sourceCollection->name,
            'last_successful_sync_at' => now(),
        ]);
        $signal = CatalogTitleRecommendationSignal::query()->create([
            'catalog_title_id' => $sourceTitle->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
            'signal_key' => $source->source_key,
            'weight' => 280,
            'observed_at' => now(),
        ]);
        $unrelated = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Unrelated public row',
            'slug' => 'unrelated-public-row',
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        $membershipCount = CatalogCollectionItem::query()->count();
        $inspection = app(CatalogCollectionPublicQualityRepairer::class)->inspect();

        self::assertSame(4, $inspection['demo_quarantine_candidates']);
        self::assertSame(1, $inspection['source_quarantine_candidates']);

        $this->artisan('catalog-collections:repair-public-quality', [
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();

        self::assertSame(6, CatalogCollection::query()
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->count());

        $activeSync = CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => CatalogCollectionSyncStatus::Running,
            'started_at' => now(),
        ]);
        $this->artisan('catalog-collections:repair-public-quality', [
            '--force' => true,
            '--json' => true,
        ])->assertFailed();
        self::assertSame(6, CatalogCollection::query()
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->count());
        $activeSync->forceFill([
            'status' => CatalogCollectionSyncStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $this->artisan('catalog-collections:repair-public-quality', [
            '--force' => true,
            '--json' => true,
        ])->assertSuccessful();

        self::assertSame(0, CatalogCollection::query()
            ->whereNotNull('owner_id')
            ->where('visibility', '!=', CatalogCollectionVisibility::Private->value)
            ->count());
        self::assertSame(CatalogCollectionVisibility::Private, $sourceCollection->fresh()->visibility);
        self::assertSame(
            CatalogCollectionModerationStatus::Archived,
            $sourceCollection->fresh()->moderation_status,
        );
        self::assertNull($sourceCollection->fresh()->published_at);
        self::assertSame(CatalogCollectionVisibility::Public, $unrelated->fresh()->visibility);
        self::assertSame($membershipCount, CatalogCollectionItem::query()->count());
        self::assertModelExists($source->fresh());
        self::assertDatabaseMissing('catalog_title_recommendation_signals', [
            'id' => $signal->id,
            'source' => 'hdrezka',
            'signal_type' => 'editorial_collection',
            'signal_key' => $source->source_key,
        ]);

        $versions = CatalogCollection::query()->orderBy('id')->pluck('content_version', 'id');
        $this->artisan('catalog-collections:repair-public-quality', [
            '--force' => true,
            '--json' => true,
        ])->assertSuccessful();
        $after = app(CatalogCollectionPublicQualityRepairer::class)->inspect();
        self::assertSame(0, $after['demo_quarantine_candidates']);
        self::assertSame(0, $after['source_quarantine_candidates']);
        self::assertSame(
            $versions->all(),
            CatalogCollection::query()->orderBy('id')->pluck('content_version', 'id')->all(),
        );
    }

    public function test_production_force_requires_backup_and_paused_writer_confirmations(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('catalog-collections:repair-public-quality', ['--force' => true])
            ->expectsOutputToContain('Production repair требует')
            ->assertFailed();
    }

    public function test_source_quarantine_does_not_require_demo_accounts_to_exist(): void
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Source without demo accounts',
            'slug' => 'source-without-demo-accounts',
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => hash('sha256', 'source-without-demo-accounts'),
            'catalog_collection_id' => $collection->id,
            'source_path' => '/xfsearch/collections/source-without-demo-accounts/',
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
        ]);

        $this->artisan('catalog-collections:repair-public-quality', [
            '--force' => true,
            '--json' => true,
        ])->assertSuccessful();

        self::assertSame(CatalogCollectionVisibility::Private, $collection->fresh()->visibility);
        self::assertSame(
            CatalogCollectionModerationStatus::Archived,
            $collection->fresh()->moderation_status,
        );
    }
}
