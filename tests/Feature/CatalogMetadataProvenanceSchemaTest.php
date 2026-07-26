<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogFieldVersion;
use App\Models\CatalogMetadataConflict;
use App\Models\CatalogMetadataObservation;
use App\Models\CatalogQualityRun;
use App\Models\CatalogTitle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogMetadataProvenanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function provenance_tables_have_explainability_columns_and_query_indexes(): void
    {
        self::assertTrue(Schema::hasColumns('catalog_metadata_observations', [
            'catalog_title_id',
            'source_id',
            'source_page_id',
            'field_key',
            'source_kind',
            'source_key',
            'value',
            'value_hash',
            'confidence',
            'is_current',
            'is_publication_eligible',
            'first_observed_at',
            'last_confirmed_at',
        ]));
        self::assertTrue(Schema::hasColumns('catalog_metadata_conflicts', [
            'catalog_title_id',
            'field_key',
            'selected_observation_id',
            'competing_observation_id',
            'selected_value_hash',
            'competing_value_hash',
            'severity',
            'status',
            'first_detected_at',
            'last_detected_at',
            'resolved_at',
        ]));
        self::assertTrue(Schema::hasColumns('catalog_field_versions', [
            'catalog_title_id',
            'field_key',
            'version',
            'observation_id',
            'actor_id',
            'source_kind',
            'value',
            'value_hash',
            'selected_at',
            'superseded_at',
        ]));
        self::assertTrue(Schema::hasColumns('catalog_quality_runs', [
            'status',
            'trigger',
            'scoring_version',
            'requested_limit',
            'processed_count',
            'issue_count',
            'started_at',
            'completed_at',
            'failure_code',
        ]));
        self::assertTrue(Schema::hasColumn('catalog_title_quality_snapshots', 'catalog_quality_run_id'));
        self::assertTrue(Schema::hasColumn('catalog_title_quality_issues', 'catalog_quality_run_id'));

        $observationIndexes = collect(DB::select("PRAGMA index_list('catalog_metadata_observations')"))
            ->pluck('name');
        self::assertContains('catalog_metadata_observation_identity_unique', $observationIndexes);
        self::assertContains('catalog_metadata_observation_current_idx', $observationIndexes);
        self::assertContains('catalog_metadata_observation_confidence_idx', $observationIndexes);

        $conflictIndexes = collect(DB::select("PRAGMA index_list('catalog_metadata_conflicts')"))
            ->pluck('name');
        self::assertContains('catalog_metadata_conflict_identity_unique', $conflictIndexes);
        self::assertContains('catalog_metadata_conflict_queue_idx', $conflictIndexes);

        $versionIndexes = collect(DB::select("PRAGMA index_list('catalog_field_versions')"))
            ->pluck('name');
        self::assertContains('catalog_field_version_unique', $versionIndexes);
        self::assertContains('catalog_field_version_current_idx', $versionIndexes);
    }

    #[Test]
    public function provenance_relations_cast_values_and_cascade_with_the_title(): void
    {
        $title = CatalogTitle::factory()->create();
        $now = now();
        $observation = CatalogMetadataObservation::query()->create([
            'catalog_title_id' => $title->id,
            'source_id' => $title->source_id,
            'source_page_id' => $title->source_page_id,
            'field_key' => 'year',
            'source_kind' => 'provider',
            'source_key' => hash('sha256', 'seasonvar'),
            'value' => 2020,
            'value_hash' => hash('sha256', '2020'),
            'confidence' => 98,
            'is_current' => true,
            'is_publication_eligible' => true,
            'first_observed_at' => $now,
            'last_confirmed_at' => $now,
        ]);
        $version = CatalogFieldVersion::query()->create([
            'catalog_title_id' => $title->id,
            'field_key' => 'year',
            'version' => 1,
            'observation_id' => $observation->id,
            'source_kind' => 'provider',
            'value' => 2020,
            'value_hash' => $observation->value_hash,
            'selected_at' => $now,
        ]);
        $conflict = CatalogMetadataConflict::query()->create([
            'catalog_title_id' => $title->id,
            'field_key' => 'year',
            'selected_observation_id' => $observation->id,
            'competing_observation_id' => $observation->id,
            'selected_value_hash' => $observation->value_hash,
            'competing_value_hash' => hash('sha256', '2021'),
            'severity' => 'warning',
            'status' => 'open',
            'first_detected_at' => $now,
            'last_detected_at' => $now,
        ]);
        $run = CatalogQualityRun::query()->create([
            'status' => 'running',
            'trigger' => 'command',
            'scoring_version' => 1,
            'requested_limit' => 25,
            'started_at' => $now,
        ]);

        self::assertSame(2020, $observation->value);
        self::assertTrue($observation->is_current);
        self::assertTrue($title->metadataObservations()->sole()->is($observation));
        self::assertTrue($title->fieldVersions()->sole()->is($version));
        self::assertTrue($title->metadataConflicts()->sole()->is($conflict));
        self::assertSame(
            $now->format('Y-m-d H:i:s'),
            $run->started_at->format('Y-m-d H:i:s'),
        );

        $title->forceDelete();

        self::assertModelMissing($observation);
        self::assertModelMissing($version);
        self::assertModelMissing($conflict);
        self::assertModelExists($run);
    }

    #[Test]
    public function observation_and_version_identities_are_unique(): void
    {
        $title = CatalogTitle::factory()->create();
        $attributes = [
            'catalog_title_id' => $title->id,
            'source_id' => $title->source_id,
            'source_page_id' => $title->source_page_id,
            'field_key' => 'year',
            'source_kind' => 'provider',
            'source_key' => hash('sha256', 'seasonvar'),
            'value' => 2020,
            'value_hash' => hash('sha256', '2020'),
            'confidence' => 98,
            'is_current' => true,
            'is_publication_eligible' => true,
            'first_observed_at' => now(),
            'last_confirmed_at' => now(),
        ];
        $observation = CatalogMetadataObservation::query()->create($attributes);
        CatalogFieldVersion::query()->create([
            'catalog_title_id' => $title->id,
            'field_key' => 'year',
            'version' => 1,
            'observation_id' => $observation->id,
            'source_kind' => 'provider',
            'value' => 2020,
            'value_hash' => $observation->value_hash,
            'selected_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CatalogMetadataObservation::query()->create($attributes);
    }

    #[Test]
    public function sqlite_query_plans_use_the_current_observation_and_conflict_queue_indexes(): void
    {
        $observationPlan = collect(DB::select(
            <<<'SQL'
                EXPLAIN QUERY PLAN
                SELECT id
                FROM catalog_metadata_observations
                WHERE catalog_title_id = ?
                  AND field_key = ?
                  AND is_current = ?
                ORDER BY last_confirmed_at DESC
                LIMIT 1
                SQL,
            [1, 'year', 1],
        ))->pluck('detail')->implode(' ');
        $conflictPlan = collect(DB::select(
            <<<'SQL'
                EXPLAIN QUERY PLAN
                SELECT id
                FROM catalog_metadata_conflicts
                WHERE status = ?
                  AND severity = ?
                ORDER BY last_detected_at DESC, catalog_title_id
                LIMIT 50
                SQL,
            ['open', 'warning'],
        ))->pluck('detail')->implode(' ');

        self::assertStringContainsString(
            'catalog_metadata_observation_current_idx',
            $observationPlan,
        );
        self::assertStringContainsString(
            'catalog_metadata_conflict_queue_idx',
            $conflictPlan,
        );
    }
}
