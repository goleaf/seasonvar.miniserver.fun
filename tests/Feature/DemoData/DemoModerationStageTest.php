<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CommentReportCategory;
use App\Enums\CommentReportStatus;
use App\Enums\CommentRestrictionType;
use App\Enums\ReviewReportCategory;
use App\Enums\ReviewReportStatus;
use App\Enums\ReviewRestrictionType;
use App\Enums\UserProfileReportCategory;
use App\Enums\UserProfileReportStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoCatalogActivityStage;
use App\Services\DemoData\Stages\DemoCommunityStage;
use App\Services\DemoData\Stages\DemoModerationStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoModerationStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 8,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.public_tag_target' => 12,
            'session.driver' => 'array',
        ]);
    }

    public function test_moderation_stage_seeds_reports_preferences_and_restrictions_idempotently(): void
    {
        CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title): void {
            $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
            $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now()->subDay(),
            ]);
        });
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        app(DemoOrganizationStage::class)->run($options);
        app(DemoCatalogActivityStage::class)->run($options);
        app(DemoCommunityStage::class)->run($options);
        $stage = app(DemoModerationStage::class);
        $first = $stage->run($options);
        $counts = $this->moderationCounts();
        $second = $stage->run($options);

        $this->assertSame('moderation', $stage->key());
        $this->assertSame(32, $first->counters['comment_reports']);
        $this->assertSame(32, $first->counters['review_reports']);
        $this->assertSame(16, $first->counters['collection_reports']);
        $this->assertSame(8, $first->counters['profile_reports']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->moderationCounts());

        $this->assertEnumCoverage('comment_reports', 'category', CommentReportCategory::cases());
        $this->assertEnumCoverage('comment_reports', 'status', CommentReportStatus::cases());
        $this->assertEnumCoverage('catalog_title_review_reports', 'category', ReviewReportCategory::cases());
        $this->assertEnumCoverage('catalog_title_review_reports', 'status', ReviewReportStatus::cases());
        $this->assertEnumCoverage('catalog_collection_reports', 'reason', CatalogCollectionReportReason::cases());
        $this->assertEnumCoverage('catalog_collection_reports', 'status', CatalogCollectionReportStatus::cases());
        $this->assertEnumCoverage('user_profile_reports', 'category', UserProfileReportCategory::cases());
        $this->assertEnumCoverage('user_profile_reports', 'status', UserProfileReportStatus::cases());
        $this->assertEnumCoverage('comment_restrictions', 'type', CommentRestrictionType::cases());
        $this->assertEnumCoverage('catalog_title_review_restrictions', 'type', ReviewRestrictionType::cases());

        $this->assertSame(16, DB::table('user_blocks')->count());
        $this->assertSame(16, DB::table('user_mutes')->count());
        $this->assertSame(0, DB::table('user_blocks')->whereColumn('blocker_id', 'blocked_id')->count());
        $this->assertSame(0, DB::table('user_mutes')->whereColumn('muter_id', 'muted_id')->count());
    }

    /** @param list<\BackedEnum> $cases */
    private function assertEnumCoverage(string $table, string $column, array $cases): void
    {
        $this->assertEqualsCanonicalizing(
            array_column($cases, 'value'),
            DB::table($table)->distinct()->pluck($column)->all(),
        );
    }

    /** @return array<string, int> */
    private function moderationCounts(): array
    {
        return collect([
            'comment_reports', 'catalog_title_review_reports', 'catalog_collection_reports', 'user_profile_reports',
            'user_blocks', 'user_mutes', 'comment_restrictions', 'catalog_title_review_restrictions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
