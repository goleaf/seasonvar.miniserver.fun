<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualitySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function quality_tables_have_relations_casts_constraints_and_queue_indexes(): void
    {
        self::assertTrue(Schema::hasColumns('catalog_title_quality_snapshots', [
            'catalog_title_id',
            'quality_score',
            'severity',
            'issue_count',
            'critical_count',
            'needs_refresh',
            'scoring_version',
            'last_source_checked_at',
            'evaluated_at',
        ]));
        self::assertTrue(Schema::hasColumns('catalog_title_quality_issues', [
            'catalog_title_id',
            'code',
            'category',
            'severity',
            'penalty',
            'evidence',
            'first_detected_at',
            'last_detected_at',
        ]));

        $title = CatalogTitle::factory()->create();
        $snapshot = CatalogTitleQualitySnapshot::query()->create([
            'catalog_title_id' => $title->id,
            'quality_score' => 63,
            'severity' => CatalogQualitySeverity::Warning,
            'issue_count' => 1,
            'critical_count' => 0,
            'needs_refresh' => false,
            'scoring_version' => 1,
            'evaluated_at' => now(),
        ]);
        $issue = CatalogTitleQualityIssue::query()->create([
            'catalog_title_id' => $title->id,
            'code' => 'missing_poster',
            'category' => CatalogQualityIssueCategory::MissingPoster,
            'severity' => CatalogQualitySeverity::Warning,
            'penalty' => 10,
            'evidence' => ['field' => 'poster_url'],
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        self::assertTrue($title->qualitySnapshot()->is($snapshot));
        self::assertTrue($title->qualityIssues()->sole()->is($issue));
        self::assertSame(['field' => 'poster_url'], $issue->evidence);
        self::assertSame(CatalogQualitySeverity::Warning, $snapshot->severity);

        $indexNames = collect(DB::select("PRAGMA index_list('catalog_title_quality_snapshots')"))
            ->pluck('name');
        self::assertContains('catalog_quality_score_idx', $indexNames);
        self::assertContains('catalog_quality_severity_score_idx', $indexNames);
        self::assertContains('catalog_quality_refresh_idx', $indexNames);

        $issueIndexNames = collect(DB::select("PRAGMA index_list('catalog_title_quality_issues')"))
            ->pluck('name');
        self::assertContains('catalog_quality_issue_queue_idx', $issueIndexNames);
        self::assertContains('catalog_quality_issue_severity_idx', $issueIndexNames);
    }

    #[Test]
    public function issue_code_is_unique_per_title(): void
    {
        $title = CatalogTitle::factory()->create();

        CatalogTitleQualitySnapshot::factory()->for($title)->create();
        CatalogTitleQualityIssue::factory()->for($title)->create([
            'code' => 'missing_video',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CatalogTitleQualityIssue::factory()->for($title)->create([
            'code' => 'missing_video',
        ]);
    }

    #[Test]
    public function derived_rows_cascade_when_a_title_is_permanently_deleted(): void
    {
        $title = CatalogTitle::factory()->create();
        CatalogTitleQualitySnapshot::factory()->for($title)->create();
        CatalogTitleQualityIssue::factory()->for($title)->create();

        $title->forceDelete();

        self::assertDatabaseMissing('catalog_title_quality_snapshots', [
            'catalog_title_id' => $title->id,
        ]);
        self::assertDatabaseMissing('catalog_title_quality_issues', [
            'catalog_title_id' => $title->id,
        ]);
    }
}
