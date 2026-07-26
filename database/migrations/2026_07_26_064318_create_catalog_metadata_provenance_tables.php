<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalog_metadata_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field_key', 48);
            $table->string('source_kind', 24);
            $table->char('source_key', 64);
            $table->json('value')->nullable();
            $table->char('value_hash', 64);
            $table->unsignedTinyInteger('confidence');
            $table->boolean('is_current')->default(true);
            $table->boolean('is_publication_eligible')->default(false);
            $table->timestamp('first_observed_at');
            $table->timestamp('last_confirmed_at');
            $table->timestamps();

            $table->unique(
                ['catalog_title_id', 'field_key', 'source_kind', 'source_key', 'value_hash'],
                'catalog_metadata_observation_identity_unique',
            );
            $table->index(
                ['catalog_title_id', 'field_key', 'is_current', 'last_confirmed_at'],
                'catalog_metadata_observation_current_idx',
            );
            $table->index(
                ['field_key', 'is_current', 'confidence', 'catalog_title_id'],
                'catalog_metadata_observation_confidence_idx',
            );
            $table->index(
                ['source_id', 'source_key', 'is_current'],
                'catalog_metadata_observation_source_idx',
            );
        });

        Schema::create('catalog_metadata_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('selected_observation_id')
                ->nullable()
                ->constrained('catalog_metadata_observations')
                ->nullOnDelete();
            $table->foreignId('competing_observation_id')
                ->nullable()
                ->constrained('catalog_metadata_observations')
                ->nullOnDelete();
            $table->string('field_key', 48);
            $table->char('selected_value_hash', 64);
            $table->char('competing_value_hash', 64);
            $table->string('severity', 16)->default('warning');
            $table->string('status', 16)->default('open');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['catalog_title_id', 'field_key', 'selected_value_hash', 'competing_value_hash'],
                'catalog_metadata_conflict_identity_unique',
            );
            $table->index(
                ['status', 'severity', 'last_detected_at', 'catalog_title_id'],
                'catalog_metadata_conflict_queue_idx',
            );
            $table->index(
                ['catalog_title_id', 'status', 'field_key'],
                'catalog_metadata_conflict_title_idx',
            );
        });

        Schema::create('catalog_field_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('observation_id')
                ->nullable()
                ->constrained('catalog_metadata_observations')
                ->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field_key', 48);
            $table->unsignedInteger('version');
            $table->string('source_kind', 24);
            $table->json('value')->nullable();
            $table->char('value_hash', 64);
            $table->timestamp('selected_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['catalog_title_id', 'field_key', 'version'],
                'catalog_field_version_unique',
            );
            $table->index(
                ['catalog_title_id', 'field_key', 'superseded_at', 'version'],
                'catalog_field_version_current_idx',
            );
            $table->index(
                ['actor_id', 'selected_at'],
                'catalog_field_version_actor_idx',
            );
        });

        Schema::create('catalog_quality_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16)->default('running');
            $table->string('trigger', 24);
            $table->unsignedSmallInteger('scoring_version');
            $table->unsignedInteger('requested_limit');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'started_at', 'id'],
                'catalog_quality_run_status_idx',
            );
            $table->index(
                ['completed_at', 'id'],
                'catalog_quality_run_completed_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_quality_runs');
        Schema::dropIfExists('catalog_field_versions');
        Schema::dropIfExists('catalog_metadata_conflicts');
        Schema::dropIfExists('catalog_metadata_observations');
    }
};
