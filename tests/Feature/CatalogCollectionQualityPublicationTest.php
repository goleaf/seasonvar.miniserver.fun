<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminAuditAction;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionQualityIssueSeverity;
use App\Enums\CatalogCollectionQualityIssueStatus;
use App\Livewire\Collections\CatalogCollectionAdministrationManager;
use App\Livewire\Collections\CatalogCollectionPage;
use App\Models\AdminAuditEvent;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionQualityIssue;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Collections\CatalogCollectionModerationService;
use App\Services\Collections\CatalogCollectionPublicationReadiness;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionQualityPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_scope_allows_legacy_rollout_but_hides_current_low_or_stale_scores(): void
    {
        config(['catalog-collections.quality.minimum_public_score' => 60]);
        $category = $this->category();
        $legacy = $this->collection($category, 'Legacy quality');
        $high = $this->collection($category, 'Current high quality');
        $low = $this->collection($category, 'Current low quality');
        $stale = $this->collection($category, 'Stale high quality');

        foreach ([$legacy, $high, $low, $stale] as $collection) {
            $this->attach($collection);
        }

        $high->forceFill([
            'quality_score' => 80,
            'quality_content_version' => $high->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        $low->forceFill([
            'quality_score' => 59,
            'quality_content_version' => $low->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        $stale->forceFill([
            'quality_score' => 90,
            'quality_content_version' => $stale->content_version,
            'quality_evaluated_at' => now()->subDays(15),
        ])->save();

        self::assertSame(
            [$legacy->id, $high->id],
            CatalogCollection::query()->publiclyListed()->orderBy('id')->pluck('id')->all(),
        );
        $this->get(route('collections.show', ['collectionSlug' => $low->slug]))
            ->assertNotFound();
        $this->get(route('collections.show', ['collectionSlug' => $stale->slug]))
            ->assertNotFound();
    }

    public function test_public_scope_hides_assessed_template_noise_even_above_the_numeric_threshold(): void
    {
        $collection = $this->collection($this->category(), 'Шаблонная подборка');
        $this->attach($collection);
        $collection->forceFill([
            'quality_score' => 80,
            'quality_content_version' => $collection->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        CatalogCollectionQualityIssue::query()->create([
            'catalog_collection_id' => $collection->id,
            'code' => 'template_content',
            'severity' => CatalogCollectionQualityIssueSeverity::Warning,
            'status' => CatalogCollectionQualityIssueStatus::Open,
            'fingerprint' => hash('sha256', 'template-'.$collection->id),
            'evidence' => ['repetitions' => 3],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        self::assertFalse(
            CatalogCollection::query()->publiclyListed()->whereKey($collection)->exists(),
        );
    }

    public function test_public_approval_assesses_current_content_and_rejects_a_low_score(): void
    {
        config([
            'catalog-collections.quality.minimum_public_score' => 60,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $category = $this->category();
        $healthy = $this->collection(
            $category,
            'Тематическая редакционная подборка',
            CatalogCollectionModerationStatus::Pending,
            published: false,
        );
        $healthy->forceFill([
            'description' => 'Содержательное описание тематической редакционной подборки для зрителей.',
        ])->save();
        $this->attach($healthy);
        $weak = $this->collection(
            $category,
            'X',
            CatalogCollectionModerationStatus::Pending,
            published: false,
        );
        $this->attach($weak);
        $service = app(CatalogCollectionModerationService::class);

        $approved = $service->moderate(
            $admin,
            $healthy,
            CatalogCollectionModerationStatus::Approved,
        );

        self::assertGreaterThanOrEqual(60, $approved->quality_score);
        self::assertSame($approved->content_version, $approved->quality_content_version);
        self::assertTrue(
            CatalogCollection::query()->publiclyListed()->whereKey($approved->id)->exists(),
        );

        try {
            $service->moderate($admin, $weak, CatalogCollectionModerationStatus::Approved);
            self::fail('Подборка ниже minimum quality score не должна публиковаться.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('moderation', $exception->errors());
        }

        self::assertSame(
            CatalogCollectionModerationStatus::Pending,
            $weak->fresh()->moderation_status,
        );
    }

    public function test_editorial_readiness_rejects_current_low_and_assessed_stale_quality(): void
    {
        config(['catalog-collections.quality.minimum_public_score' => 60]);
        $category = $this->category();
        $low = $this->collection($category, 'Low quality');
        $stale = $this->collection($category, 'Stale quality');
        $legacy = $this->collection($category, 'Legacy rollout');

        foreach ([$low, $stale, $legacy] as $collection) {
            $this->attach($collection);
        }

        $low->forceFill([
            'quality_score' => 59,
            'quality_content_version' => $low->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        $stale->forceFill([
            'quality_score' => 90,
            'quality_content_version' => $stale->content_version - 1,
            'quality_evaluated_at' => now(),
        ])->save();

        $results = app(CatalogCollectionPublicationReadiness::class)->evaluateMany([
            $low,
            $stale,
            $legacy,
        ]);

        self::assertContains('low_quality', $results[$low->id]['reason_codes']);
        self::assertContains('stale_quality', $results[$stale->id]['reason_codes']);
        self::assertNotContains('stale_quality', $results[$legacy->id]['reason_codes']);
    }

    public function test_moderator_can_verify_only_current_eligible_quality_with_an_audit_trail(): void
    {
        config([
            'catalog-collections.quality.minimum_public_score' => 50,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection($this->category(), 'Проверенная тематическая подборка');
        $collection->forceFill([
            'description' => 'Содержательное описание тематической редакционной подборки для зрителей.',
        ])->save();
        $this->attach($collection);
        $service = app(CatalogCollectionModerationService::class);

        $verified = $service->verifyQuality($admin, $collection, true);

        self::assertNotNull($verified->editorially_verified_at);
        self::assertSame($admin->id, $verified->editorially_verified_by_id);
        self::assertSame($verified->content_version, $verified->editorially_verified_content_version);
        self::assertSame($verified->content_version, $verified->quality_content_version);
        self::assertDatabaseHas('admin_audit_events', [
            'action' => AdminAuditAction::CollectionQualityVerified->value,
            'resource_type' => 'catalog_collection',
            'resource_id' => $collection->id,
        ]);

        $service->verifyQuality($admin, $verified, true);

        self::assertSame(
            1,
            AdminAuditEvent::query()
                ->where('action', AdminAuditAction::CollectionQualityVerified->value)
                ->count(),
        );
    }

    public function test_quality_verification_rejects_non_moderators(): void
    {
        config([
            'catalog-collections.quality.minimum_public_score' => 60,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $viewer = User::factory()->create();
        $collection = $this->collection($this->category(), 'Слабая подборка');
        $this->attach($collection);
        $collection->forceFill([
            'quality_score' => 59,
            'quality_content_version' => $collection->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        $service = app(CatalogCollectionModerationService::class);

        $this->expectException(AuthorizationException::class);
        $service->verifyQuality($viewer, $collection, true);
    }

    public function test_quality_verification_rejects_scores_below_threshold(): void
    {
        config([
            'catalog-collections.quality.minimum_public_score' => 60,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection($this->category(), 'Слабая подборка');
        $this->attach($collection);
        $collection->forceFill([
            'quality_score' => 59,
            'quality_content_version' => $collection->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        $service = app(CatalogCollectionModerationService::class);

        try {
            $service->verifyQuality($admin, $collection, true);
            self::fail('Оценка ниже порога не должна получать редакционную проверку.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('quality', $exception->errors());
        }
    }

    public function test_administration_exposes_quality_evidence_and_verification_action(): void
    {
        config([
            'catalog-collections.quality.minimum_public_score' => 50,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection($this->category(), 'Административная проверка качества');
        $collection->forceFill([
            'description' => 'Содержательное описание тематической редакционной подборки для зрителей.',
        ])->save();
        $this->attach($collection);
        app(CatalogCollectionModerationService::class)->moderate(
            $admin,
            $collection,
            CatalogCollectionModerationStatus::Approved,
        );
        $collection->refresh();

        Livewire::actingAs($admin)
            ->test(CatalogCollectionAdministrationManager::class)
            ->assertSeeHtml('data-collection-quality-score="'.$collection->public_id.'"')
            ->assertSeeText((string) $collection->quality_score)
            ->assertSeeHtml('data-collection-quality-components="'.$collection->public_id.'"')
            ->assertSeeHtml('data-collection-quality-signals="'.$collection->public_id.'"')
            ->assertSeeText(__('collections.admin.quality_components.theme'))
            ->assertSeeText(__('collections.admin.quality_signals.saves'))
            ->assertSeeText(__('collections.admin.quality_filters.theme'))
            ->assertSeeHtml('data-collection-quality-verification="'.$collection->public_id.'"')
            ->call('verifyQuality', $collection->public_id, true)
            ->assertHasNoErrors()
            ->assertSeeText(__('collections.admin.quality_verified'));
    }

    public function test_quality_queue_filters_combine_with_search_and_current_version_state(): void
    {
        config(['catalog-collections.quality.minimum_public_score' => 60]);
        $category = $this->category();
        $low = $this->collection($category, 'Низкая криминальная подборка');
        $stale = $this->collection($category, 'Устаревшая подборка');
        $userStale = $this->collection($category, 'Устаревшая пользовательская подборка');
        $verified = $this->collection($category, 'Проверенная подборка');

        foreach ([$low, $stale, $userStale, $verified] as $collection) {
            $this->attach($collection);
        }

        $low->forceFill([
            'quality_score' => 40,
            'quality_content_version' => $low->content_version,
            'quality_evaluated_at' => now(),
        ])->save();
        CatalogCollectionQualityIssue::query()->create([
            'catalog_collection_id' => $low->id,
            'code' => 'weak_theme',
            'severity' => CatalogCollectionQualityIssueSeverity::Critical,
            'status' => CatalogCollectionQualityIssueStatus::Open,
            'fingerprint' => hash('sha256', 'critical-'.$low->id),
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);
        $stale->forceFill([
            'quality_score' => 80,
            'quality_content_version' => $stale->content_version - 1,
            'quality_evaluated_at' => now(),
        ])->save();
        $userStale->forceFill([
            'type' => 'user',
            'quality_score' => 80,
            'quality_content_version' => $userStale->content_version - 1,
            'quality_evaluated_at' => now(),
        ])->save();
        $verified->forceFill([
            'quality_score' => 90,
            'quality_content_version' => $verified->content_version,
            'quality_evaluated_at' => now(),
            'editorially_verified_at' => now(),
            'editorially_verified_content_version' => $verified->content_version,
        ])->save();
        $query = app(CatalogCollectionQuery::class);

        self::assertSame(
            [$low->id],
            $query->moderationQueue('криминальная', 'low')->pluck('id')->all(),
        );
        self::assertSame(
            [$low->id],
            $query->moderationQueue('', 'critical')->pluck('id')->all(),
        );
        self::assertSame(
            [$low->id],
            $query->moderationQueue('', 'theme')->pluck('id')->all(),
        );
        self::assertEqualsCanonicalizing(
            [$stale->id, $userStale->id],
            $query->moderationQueue('', 'stale')->pluck('id')->all(),
        );
        self::assertSame(
            [$verified->id],
            $query->moderationQueue('', 'verified')->pluck('id')->all(),
        );
    }

    public function test_public_page_explains_dynamic_verified_collection_membership(): void
    {
        $collection = $this->collection($this->category(), 'Объяснимая динамическая подборка');
        $title = CatalogTitle::factory()->create();
        LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        $item = CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $item->forceFill([
            'theme_match_percent' => 80,
            'inclusion_reason_code' => 'title_theme',
            'quality_content_version' => $collection->content_version,
        ])->save();
        CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => 'quality-public-'.$collection->id,
            'catalog_collection_id' => $collection->id,
            'source_path' => '/collections/quality-public-'.$collection->id,
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
        ]);
        $collection->forceFill([
            'quality_score' => 80,
            'quality_content_version' => $collection->content_version,
            'quality_evaluated_at' => now(),
            'editorially_verified_at' => now(),
            'editorially_verified_content_version' => $collection->content_version,
        ])->save();

        Livewire::test(CatalogCollectionPage::class, [
            'collectionSlug' => $collection->slug,
        ])
            ->assertSeeHtml('data-collection-editorially-verified')
            ->assertSeeHtml('data-collection-dynamic')
            ->assertSeeHtml('data-collection-quality-score="80"')
            ->assertSeeText(__('collections.quality.score_badge', ['score' => 80]))
            ->assertSeeHtml('data-collection-theme-match="'.$item->theme_match_percent.'"')
            ->assertSeeText(__('collections.quality.inclusion_reasons.title_theme'));
    }

    private function category(): CatalogCollectionCategory
    {
        return CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'quality-publication-'.Str::lower(Str::random(8)),
            'position' => 1,
            'is_active' => true,
        ]);
    }

    private function collection(
        CatalogCollectionCategory $category,
        string $name,
        CatalogCollectionModerationStatus $moderation = CatalogCollectionModerationStatus::Approved,
        bool $published = true,
    ): CatalogCollection {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'type' => 'editorial',
            'visibility' => 'public',
            'moderation_status' => $moderation,
            'content_version' => 1,
            'published_at' => $published ? now() : null,
        ]);
    }

    private function attach(CatalogCollection $collection): void
    {
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
}
