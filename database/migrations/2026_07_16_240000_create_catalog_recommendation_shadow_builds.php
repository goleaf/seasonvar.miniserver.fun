<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_recommendation_builds', function (Blueprint $table): void {
            $table->id();
            $table->string('algorithm_version', 32);
            $table->string('feature_version', 32);
            $table->string('status', 16);
            $table->json('metrics')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'catalog_recommendation_builds_status_created_idx');
        });

        Schema::create('catalog_recommendation_build_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('build_id')->constrained('catalog_recommendation_builds')->cascadeOnDelete();
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommended_title_id')->constrained('catalog_titles')->cascadeOnDelete();
            $table->unsignedInteger('score');
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('matched_features_count')->default(0);
            $table->unsignedInteger('metadata_score')->default(0);
            $table->unsignedInteger('source_score')->default(0);
            $table->unsignedInteger('quality_score')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(
                ['build_id', 'catalog_title_id', 'recommended_title_id'],
                'catalog_recommendation_build_rows_pair_unique',
            );
            $table->index(
                ['build_id', 'catalog_title_id', 'rank'],
                'catalog_recommendation_build_rows_source_rank_idx',
            );
            $table->index(
                ['build_id', 'recommended_title_id', 'score'],
                'catalog_recommendation_build_rows_candidate_score_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recommendation_build_rows');
        Schema::dropIfExists('catalog_recommendation_builds');
    }
};
