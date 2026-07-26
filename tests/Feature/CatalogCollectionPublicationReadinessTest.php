<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminAuditAction;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReadinessReason;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionAdministrationManager;
use App\Models\AdminAuditEvent;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Collections\CatalogCollectionModerationService;
use App\Services\Collections\CatalogCollectionPublicationReadiness;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogCollectionPublicationReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function readiness_uses_distinct_local_and_source_thresholds_and_rejects_unavailable_membership(): void
    {
        $readyLocal = $this->collection('ready-local');
        $thinLocal = $this->collection('thin-local');
        $readySource = $this->collection('ready-source');
        $thinSource = $this->collection('thin-source');
        $partlyUnavailable = $this->collection('partly-unavailable');

        $this->attachWatchableTitles($readyLocal, 12);
        $this->attachWatchableTitles($thinLocal, 11);
        $this->attachWatchableTitles($readySource, 4);
        $this->attachWatchableTitles($thinSource, 3);
        $this->attachWatchableTitles($partlyUnavailable, 11);
        $this->attachUnavailableTitle($partlyUnavailable, 12);
        $this->source($readySource);
        $this->source($thinSource);

        $results = app(CatalogCollectionPublicationReadiness::class)->evaluateMany([
            $readyLocal,
            $thinLocal,
            $readySource,
            $thinSource,
            $partlyUnavailable,
        ]);

        self::assertSame([
            'ready' => true,
            'visible_items' => 12,
            'total_items' => 12,
            'unavailable_items' => 0,
            'required_items' => 12,
            'source_managed' => false,
            'reason_codes' => [],
        ], $results[$readyLocal->id]);
        self::assertSame(12, $results[$thinLocal->id]['required_items']);
        self::assertSame(
            [CatalogCollectionReadinessReason::InsufficientVisibleItems->value],
            $results[$thinLocal->id]['reason_codes'],
        );
        self::assertTrue($results[$readySource->id]['ready']);
        self::assertSame(4, $results[$readySource->id]['required_items']);
        self::assertSame(
            [CatalogCollectionReadinessReason::InsufficientVisibleItems->value],
            $results[$thinSource->id]['reason_codes'],
        );
        self::assertFalse($results[$partlyUnavailable->id]['ready']);
        self::assertSame(11, $results[$partlyUnavailable->id]['visible_items']);
        self::assertSame(12, $results[$partlyUnavailable->id]['total_items']);
        self::assertSame(1, $results[$partlyUnavailable->id]['unavailable_items']);
        self::assertSame([
            CatalogCollectionReadinessReason::InsufficientVisibleItems->value,
            CatalogCollectionReadinessReason::UnavailableItems->value,
        ], $results[$partlyUnavailable->id]['reason_codes']);
    }

    #[Test]
    public function readiness_fails_closed_for_missing_source_and_structural_mismatch(): void
    {
        $missingSource = $this->collection('missing-source');
        $unpublished = $this->collection('unpublished', published: false);
        $notEditorial = $this->collection('not-editorial');
        $notPublic = $this->collection('not-public');
        $notApproved = $this->collection('not-approved');
        $deleted = $this->collection('deleted');
        $this->attachWatchableTitles($missingSource, 4);
        $this->attachWatchableTitles($unpublished, 12);
        $this->attachWatchableTitles($notEditorial, 12);
        $this->attachWatchableTitles($notPublic, 12);
        $this->attachWatchableTitles($notApproved, 12);
        $this->attachWatchableTitles($deleted, 12);
        $this->source($missingSource, missing: true);
        $notEditorial->forceFill(['type' => CatalogCollectionType::User])->save();
        $notPublic->forceFill(['visibility' => CatalogCollectionVisibility::Unlisted])->save();
        $notApproved->forceFill([
            'moderation_status' => CatalogCollectionModerationStatus::Pending,
        ])->save();
        $deleted->delete();

        $results = app(CatalogCollectionPublicationReadiness::class)->evaluateMany([
            $missingSource,
            $unpublished,
            $notEditorial,
            $notPublic,
            $notApproved,
            $deleted,
        ]);

        self::assertSame(
            [CatalogCollectionReadinessReason::SourceMissing->value],
            $results[$missingSource->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::NotPublished->value],
            $results[$unpublished->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::NotEditorial->value],
            $results[$notEditorial->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::NotPublic->value],
            $results[$notPublic->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::NotApproved->value],
            $results[$notApproved->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::Deleted->value],
            $results[$deleted->id]['reason_codes'],
        );
    }

    #[Test]
    public function readiness_reports_missing_inactive_and_oversized_public_structure(): void
    {
        config(['catalog-collections.maximum_public_items_per_collection' => 12]);
        $missingCategory = $this->collection('missing-category');
        $inactiveCategory = $this->collection('inactive-category');
        $oversized = $this->collection('oversized');
        $missingCategory->forceFill(['catalog_collection_category_id' => null])->save();
        $inactiveCategory->category()->update(['is_active' => false]);
        $this->attachWatchableTitles($missingCategory, 12);
        $this->attachWatchableTitles($inactiveCategory, 12);
        $this->attachWatchableTitles($oversized, 13);

        $results = app(CatalogCollectionPublicationReadiness::class)->evaluateMany([
            $missingCategory,
            $inactiveCategory,
            $oversized,
        ]);

        self::assertSame(
            [CatalogCollectionReadinessReason::MissingCategory->value],
            $results[$missingCategory->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::InactiveCategory->value],
            $results[$inactiveCategory->id]['reason_codes'],
        );
        self::assertSame(
            [CatalogCollectionReadinessReason::TooManyItems->value],
            $results[$oversized->id]['reason_codes'],
        );
    }

    #[Test]
    public function readiness_fails_closed_when_collection_disappears_before_the_grouped_read(): void
    {
        $deleted = $this->collection('hard-deleted-before-read');
        $this->attachWatchableTitles($deleted, 12);
        CatalogCollection::query()->whereKey($deleted->id)->forceDelete();

        $result = app(CatalogCollectionPublicationReadiness::class)->evaluateMany([$deleted])[$deleted->id];

        self::assertFalse($result['ready']);
        self::assertSame(0, $result['visible_items']);
        self::assertSame(0, $result['total_items']);
        self::assertSame([
            CatalogCollectionReadinessReason::Deleted->value,
            CatalogCollectionReadinessReason::InsufficientVisibleItems->value,
        ], $result['reason_codes']);
    }

    #[Test]
    public function feature_guard_preserves_state_on_failure_and_ready_retry_is_idempotent(): void
    {
        config([
            'cache-architecture.stores.versions' => 'array',
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $thin = $this->collection('feature-thin');
        $ready = $this->collection('feature-ready');
        $this->attachWatchableTitles($thin, 11);
        $this->attachWatchableTitles($ready, 12);
        $this->markCurrentQuality($ready);
        $service = app(CatalogCollectionModerationService::class);

        try {
            $service->feature($admin, $thin, true);
            self::fail('Тонкая подборка не должна получать редакционный статус.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [__('collections.errors.feature_not_ready')],
                $exception->errors()['feature'],
            );
        }

        $thin->refresh();
        self::assertFalse($thin->is_featured);
        self::assertSame(1, $thin->content_version);
        self::assertSame(0, AdminAuditEvent::query()->count());

        $featured = $service->feature($admin, $ready, true);
        self::assertTrue($featured->is_featured);
        self::assertSame(2, $featured->content_version);
        self::assertSame(
            1,
            AdminAuditEvent::query()
                ->where('action', AdminAuditAction::CollectionFeatured->value)
                ->count(),
        );
        $versions = app(CacheVersionRegistry::class);
        $cacheVersions = collect(CacheDomain::cases())
            ->mapWithKeys(fn (CacheDomain $domain): array => [
                $domain->value => $versions->version($domain),
            ])
            ->all();

        $retried = $service->feature($admin, $featured, true);
        self::assertTrue($retried->is_featured);
        self::assertSame(2, $retried->content_version);
        self::assertSame(
            1,
            AdminAuditEvent::query()
                ->where('action', AdminAuditAction::CollectionFeatured->value)
                ->count(),
        );
        self::assertSame(
            $cacheVersions,
            collect(CacheDomain::cases())
                ->mapWithKeys(fn (CacheDomain $domain): array => [
                    $domain->value => $versions->version($domain),
                ])
                ->all(),
        );
    }

    #[Test]
    public function stale_featured_collection_keeps_unfeature_control_and_can_be_unfeatured(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection('stale-featured');
        $this->attachWatchableTitles($collection, 12);
        $this->markCurrentQuality($collection);
        $service = app(CatalogCollectionModerationService::class);
        $featured = $service->feature($admin, $collection, true);

        LicensedMedia::query()
            ->where('catalog_title_id', CatalogCollectionItem::query()
                ->where('catalog_collection_id', $collection->id)
                ->orderBy('position')
                ->value('catalog_title_id'))
            ->delete();

        self::assertFalse(app(CatalogCollectionPublicationReadiness::class)->evaluate($featured)['ready']);

        Livewire::actingAs($admin)
            ->test(app(CatalogCollectionAdministrationManager::class))
            ->assertSeeHtml('data-collection-readiness="'.$collection->public_id.'"')
            ->assertSeeHtml('data-collection-readiness-state="not-ready"')
            ->assertSeeHtml('data-collection-feature-action="'.$collection->public_id.'"')
            ->assertSeeText(__('collections.admin.unfeature'));

        $unfeatured = $service->feature($admin, $featured, false);

        self::assertFalse($unfeatured->is_featured);
        self::assertSame(3, $unfeatured->content_version);
    }

    #[Test]
    public function administration_shows_prepared_readiness_and_only_offers_feature_for_ready_rows(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $ready = $this->collection('admin-ready');
        $thin = $this->collection('admin-thin');
        $this->attachWatchableTitles($ready, 12);
        $this->attachWatchableTitles($thin, 11);

        Livewire::actingAs($admin)
            ->test(app(CatalogCollectionAdministrationManager::class))
            ->assertSeeHtml('data-collection-readiness="'.$ready->public_id.'"')
            ->assertSeeHtml('data-collection-readiness-state="ready"')
            ->assertSeeText(__('collections.admin.readiness_ready'))
            ->assertSeeText(__('collections.admin.readiness_count', [
                'visible' => 12,
                'total' => 12,
                'required' => 12,
            ]))
            ->assertSeeHtml('data-collection-feature-action="'.$ready->public_id.'"')
            ->assertSeeHtml('data-collection-readiness="'.$thin->public_id.'"')
            ->assertSeeHtml('data-collection-readiness-state="not-ready"')
            ->assertSeeText(__('collections.admin.readiness_not_ready'))
            ->assertSeeText(CatalogCollectionReadinessReason::InsufficientVisibleItems->label())
            ->assertDontSeeHtml('data-collection-feature-action="'.$thin->public_id.'"');
    }

    #[Test]
    public function administration_evaluates_the_current_page_in_one_grouped_readiness_query(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        foreach (range(1, 10) as $index) {
            $this->attachWatchableTitles($this->collection('admin-batch-'.$index), 1);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'readiness_collections')) {
                $queries[] = $query->sql;
            }
        });

        Livewire::actingAs($admin)
            ->test(app(CatalogCollectionAdministrationManager::class))
            ->assertSeeHtml('data-collection-readiness-state="not-ready"');

        self::assertCount(1, $queries);
        self::assertStringContainsString('group by', strtolower($queries[0]));
    }

    private function collection(string $slug, bool $published = true): CatalogCollection
    {
        $category = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'readiness-'.$slug,
            'position' => 100,
            'is_active' => true,
        ]);

        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => null,
            'catalog_collection_category_id' => $category->id,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'description' => null,
            'slug' => $slug,
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'content_version' => 1,
            'published_at' => $published ? now() : null,
        ]);
    }

    private function attachWatchableTitles(CatalogCollection $collection, int $count): void
    {
        CatalogTitle::factory()
            ->count($count)
            ->create()
            ->each(function (CatalogTitle $title, int $index) use ($collection): void {
                LicensedMedia::factory()->for($title)->create([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
                CatalogCollectionItem::query()->create([
                    'catalog_collection_id' => $collection->id,
                    'catalog_title_id' => $title->id,
                    'position' => $index + 1,
                ]);
            });
    }

    private function attachUnavailableTitle(CatalogCollection $collection, int $position): void
    {
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'position' => $position,
        ]);
    }

    private function source(CatalogCollection $collection, bool $missing = false): void
    {
        CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => 'readiness-'.$collection->id,
            'catalog_collection_id' => $collection->id,
            'source_path' => '/collections/'.$collection->slug,
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
            'missing_since_at' => $missing ? now() : null,
        ]);
    }

    private function markCurrentQuality(
        CatalogCollection $collection,
        int $score = 80,
    ): void {
        $collection->forceFill([
            'quality_score' => $score,
            'quality_content_version' => $collection->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
    }
}
