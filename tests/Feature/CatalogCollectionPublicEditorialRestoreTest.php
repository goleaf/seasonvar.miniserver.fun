<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\SeasonvarImportRun;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionPublicQualityRepairer;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\Import\HdRezkaPublicCollectionRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionPublicEditorialRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const ANIME_SOURCE_KEY = 'f649faa975cd16579a2169bfc7c07746d6394bfa6f94e35636ed8278ed2c9965';

    private const NEW_RELEASES_SOURCE_KEY = '9a9356bc09b43aaddf98917f48d675ae004777da5769deef60a12456f34cec09';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.stores.versions' => 'array',
            'catalog-collections.quality.minimum_public_score' => 0,
        ]);
    }

    public function test_dry_run_reports_only_exact_reviewed_source_records_without_writing(): void
    {
        $reviewed = $this->sourceCollection(self::ANIME_SOURCE_KEY);
        $unrelated = $this->sourceCollection(str_repeat('f', 64));
        $version = $reviewed->content_version;
        $membershipCount = CatalogCollectionItem::query()->count();
        $sourceCount = CatalogCollectionSource::query()->count();

        $inspection = app(HdRezkaPublicCollectionRestorer::class)->inspect();

        self::assertSame(10, $inspection['reviewed_source_keys']);
        self::assertSame(1, $inspection['matched_records']);
        self::assertSame(9, $inspection['missing_records']);
        self::assertSame(1, $inspection['restorable_records']);
        self::assertSame(0, $inspection['already_restored_records']);
        self::assertSame(0, $inspection['category_conflicts']);
        self::assertSame(0, $inspection['ineligible_records']);

        $this->artisan('catalog-collections:restore-public-editorial', [
            '--dry-run' => true,
            '--json' => true,
        ])->assertSuccessful();

        self::assertNull($reviewed->fresh()->catalog_collection_category_id);
        self::assertSame($version, $reviewed->fresh()->content_version);
        self::assertSame(CatalogCollectionVisibility::Private, $reviewed->fresh()->visibility);
        self::assertSame(CatalogCollectionModerationStatus::Archived, $reviewed->fresh()->moderation_status);
        self::assertNull($unrelated->fresh()->catalog_collection_category_id);
        self::assertSame($membershipCount, CatalogCollectionItem::query()->count());
        self::assertSame($sourceCount, CatalogCollectionSource::query()->count());
    }

    public function test_force_restores_category_public_state_and_current_quality_idempotently(): void
    {
        $reviewed = $this->sourceCollection(self::ANIME_SOURCE_KEY);
        $unrelated = $this->sourceCollection(str_repeat('e', 64));
        $membershipIds = CatalogCollectionItem::query()->orderBy('id')->pluck('id')->all();
        $sourceIds = CatalogCollectionSource::query()->orderBy('id')->pluck('id')->all();

        $result = app(HdRezkaPublicCollectionRestorer::class)->repair();
        $restored = $reviewed->fresh()->load('category.parent');

        self::assertSame(1, $result['counters']['records_restored']);
        self::assertSame(1, $result['counters']['quality_refreshed']);
        self::assertSame(1, $result['after']['already_restored_records']);
        self::assertSame(1, $result['after']['publicly_listed_records']);
        self::assertSame('animation-and-anime', $restored->category?->slug);
        self::assertSame(CatalogCollectionVisibility::Public, $restored->visibility);
        self::assertSame(CatalogCollectionModerationStatus::Approved, $restored->moderation_status);
        self::assertNotNull($restored->published_at);
        self::assertFalse($restored->is_featured);
        self::assertSame($restored->content_version, $restored->quality_content_version);
        self::assertNotNull($restored->quality_evaluated_at);
        self::assertTrue(
            app(CatalogCollectionQuery::class)
                ->publicDirectory(
                    category: $restored->category->parent?->slug,
                    subcategory: 'animation-and-anime',
                )
                ->contains('id', $restored->id),
        );
        $directoryTree = app(CatalogCollectionCategoryQuery::class)->publicDirectoryTree();
        $restoredCategory = $directoryTree['tree']
            ->firstWhere('id', $restored->category->parent_id)
            ?->children
            ->firstWhere('id', $restored->category->id);
        self::assertSame(1, $directoryTree['total']);
        self::assertSame(1, $restoredCategory?->getAttribute('public_collections_count'));
        self::assertSame(
            0,
            app(CatalogCollectionPublicQualityRepairer::class)
                ->inspect()['source_quarantine_candidates'],
        );
        $this->getJson(route('api.v1.collections.show', [
            'collectionSlug' => $restored->slug,
        ]))->assertOk();
        self::assertNull($unrelated->fresh()->catalog_collection_category_id);
        self::assertSame($membershipIds, CatalogCollectionItem::query()->orderBy('id')->pluck('id')->all());
        self::assertSame($sourceIds, CatalogCollectionSource::query()->orderBy('id')->pluck('id')->all());

        $version = $restored->content_version;
        $retry = app(HdRezkaPublicCollectionRestorer::class)->repair();

        self::assertSame(0, $retry['counters']['records_restored']);
        self::assertSame(0, $retry['counters']['quality_refreshed']);
        self::assertSame($version, $reviewed->fresh()->content_version);
    }

    public function test_conflicting_category_and_empty_collection_fail_closed(): void
    {
        $conflictCategory = CatalogCollectionCategory::query()
            ->where('slug', 'comedy')
            ->firstOrFail();
        $conflict = $this->sourceCollection(
            self::ANIME_SOURCE_KEY,
            category: $conflictCategory,
        );
        $empty = $this->sourceCollection(
            self::NEW_RELEASES_SOURCE_KEY,
            withItem: false,
        );
        $conflictVersion = $conflict->content_version;
        $emptyVersion = $empty->content_version;

        $inspection = app(HdRezkaPublicCollectionRestorer::class)->inspect();

        self::assertSame(2, $inspection['matched_records']);
        self::assertSame(1, $inspection['category_conflicts']);
        self::assertSame(1, $inspection['ineligible_records']);
        self::assertSame(0, $inspection['restorable_records']);

        $result = app(HdRezkaPublicCollectionRestorer::class)->repair();

        self::assertSame(0, $result['counters']['records_restored']);
        self::assertSame($conflictCategory->id, $conflict->fresh()->catalog_collection_category_id);
        self::assertSame($conflictVersion, $conflict->fresh()->content_version);
        self::assertNull($empty->fresh()->catalog_collection_category_id);
        self::assertSame($emptyVersion, $empty->fresh()->content_version);
    }

    public function test_force_is_blocked_while_any_writer_is_active(): void
    {
        $reviewed = $this->sourceCollection(self::ANIME_SOURCE_KEY);
        $import = SeasonvarImportRun::query()->create([
            'mode' => 'all',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->artisan('catalog-collections:restore-public-editorial', [
            '--force' => true,
            '--json' => true,
        ])->assertFailed();

        self::assertNull($reviewed->fresh()->catalog_collection_category_id);
        self::assertSame(CatalogCollectionVisibility::Private, $reviewed->fresh()->visibility);
        $import->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        $sync = CatalogCollectionSyncRun::query()->create([
            'provider' => 'hdrezka',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->artisan('catalog-collections:restore-public-editorial', [
            '--force' => true,
            '--json' => true,
        ])->assertFailed();

        self::assertNull($reviewed->fresh()->catalog_collection_category_id);
        $sync->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'building',
            'started_at' => now(),
        ]);

        $this->artisan('catalog-collections:restore-public-editorial', [
            '--force' => true,
            '--json' => true,
        ])->assertFailed();

        self::assertNull($reviewed->fresh()->catalog_collection_category_id);
    }

    public function test_production_force_requires_backup_and_paused_writer_confirmations(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('catalog-collections:restore-public-editorial', [
            '--force' => true,
        ])
            ->expectsOutputToContain('Для восстановления в рабочей среде требуются')
            ->assertFailed();
    }

    private function sourceCollection(
        string $sourceKey,
        bool $withItem = true,
        ?CatalogCollectionCategory $category = null,
    ): CatalogCollection {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category?->id,
            'name' => 'Проверяемая редакционная подборка',
            'slug' => 'reviewed-editorial-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Archived,
            'content_version' => 1,
            'published_at' => null,
        ]);
        CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => $sourceKey,
            'catalog_collection_id' => $collection->id,
            'source_path' => '/collections/reviewed-'.Str::lower(Str::random(8)),
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
        ]);

        if ($withItem) {
            $title = CatalogTitle::factory()->create();
            LicensedMedia::factory()->for($title)->create([
                'status' => 'published',
                'published_at' => now(),
            ]);
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => $title->id,
                'position' => 1,
            ]);
        }

        return $collection;
    }
}
