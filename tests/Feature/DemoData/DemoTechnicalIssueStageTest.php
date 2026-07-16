<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\TechnicalIssueMessageVisibility;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\TechnicalIssue;
use App\Models\Translation;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoTechnicalIssueStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoTechnicalIssueStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 10,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.issues.minimum' => 6,
            'demo-data.issues.maximum' => 6,
            'session.driver' => 'array',
        ]);
    }

    public function test_technical_issue_stage_covers_every_issue_type_and_support_surface_idempotently(): void
    {
        $translation = Translation::query()->create(['name' => 'Русская озвучка', 'slug' => 'russian-voice']);
        CatalogTitle::factory()->count(24)->create()->each(function (CatalogTitle $title) use ($translation): void {
            $title->translations()->attach($translation->id);
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
        $stage = app(DemoTechnicalIssueStage::class);
        $first = $stage->run($options);
        $counts = $this->technicalIssueCounts();
        $second = $stage->run($options);

        $this->assertSame('technical_issues', $stage->key());
        $this->assertSame(60, $first->counters['issues']);
        $this->assertSame($first->counters, $second->counters);
        $this->assertSame($counts, $this->technicalIssueCounts());
        $this->assertEnumCoverage('technical_issues', 'type', TechnicalIssueType::cases());
        $this->assertEnumCoverage('technical_issues', 'status', TechnicalIssueStatus::cases());
        $this->assertEnumCoverage('technical_issues', 'target_type', TechnicalIssueTargetType::cases());
        $this->assertEnumCoverage('technical_issues', 'severity', TechnicalIssueSeverity::cases());
        $this->assertEnumCoverage('technical_issues', 'priority', TechnicalIssuePriority::cases());
        $this->assertEnumCoverage('technical_issues', 'resolution_type', TechnicalIssueResolutionType::cases());
        $this->assertEnumCoverage('technical_issue_messages', 'visibility', TechnicalIssueMessageVisibility::cases());

        $issues = TechnicalIssue::query()->get();

        $this->assertCount(60, $issues);
        $this->assertSame(60, $issues->pluck('summary')->unique()->count());

        foreach ($issues as $issue) {
            $this->assertSame(1, $issue->diagnostic()->count());
            $this->assertGreaterThanOrEqual(3, $issue->messages()->count());
            $this->assertSame(1, $issue->attachments()->count());
            $this->assertGreaterThanOrEqual(1, $issue->statusHistory()->count());
            $this->assertSame(1, $issue->assignments()->count());
            $this->assertGreaterThanOrEqual(1, $issue->confirmations()->count());
            $this->assertGreaterThanOrEqual(1, $issue->followers()->count());
            $this->assertGreaterThanOrEqual(1, $issue->occurrences()->count());
        }

        Storage::disk('uploads')->assertExists($issues->firstOrFail()->attachments()->value('path'));
    }

    /** @param list<\BackedEnum> $cases */
    private function assertEnumCoverage(string $table, string $column, array $cases): void
    {
        $this->assertEqualsCanonicalizing(
            array_column($cases, 'value'),
            DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->all(),
        );
    }

    /** @return array<string, int> */
    private function technicalIssueCounts(): array
    {
        return collect([
            'technical_issues', 'technical_issue_diagnostics', 'technical_issue_messages', 'technical_issue_attachments',
            'technical_issue_status_histories', 'technical_issue_assignments', 'technical_issue_confirmations',
            'technical_issue_followers', 'technical_issue_occurrences', 'technical_issue_merges',
            'technical_issue_redactions', 'technical_issue_source_actions',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
